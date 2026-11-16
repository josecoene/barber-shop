<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Regras dos pagamentos, usadas ao registrar (store), ao corrigir (update) e
 * ao mudar o status:
 *
 * - só atendimento concluído tem pagamento, e no máximo um: pendente, pago ou
 *   estornado, o pagamento registrado é o único do atendimento;
 * - o valor padrão é o price copiado no agendamento;
 * - o status padrão é pago. O servidor preenche o paid_at com o now() quando o
 *   pagamento vira pago; pendente fica com o paid_at nulo, e o estorno mantém
 *   a data em que foi pago;
 * - as transições ficam no App\Enums\PaymentStatus (pendente para pago, pago
 *   para estornado). Quem pode fazer cada uma fica na PaymentPolicy;
 * - o pagamento de um cliente excluído não leva observações, nem no registro
 *   nem na correção (Client::NOTES_AFTER_DELETION): a exclusão apagou as
 *   dele, e um texto novo voltaria a ligar dado pessoal ao cadastro;
 * - com use_loyalty_reward, o pagamento usa um prêmio da fidelidade
 *   (App\Services\LoyaltyService): o desconto vem da config, o valor é o que
 *   sobra do price (amount enviado junto é recusado) e loyalty_reward fica
 *   true. Sem prêmio disponível, 422 em use_loyalty_reward.
 *
 * Os erros saem como 422 de validação, no campo do formulário.
 *
 * Concorrência: registrar roda numa transação que trava a linha do cliente e
 * depois a do agendamento, na ordem global das travas (barbeiro, cliente,
 * serviço, agendamento, pagamento; nunca o contrário), antes de conferir o
 * status, se já existe pagamento e os prêmios da fidelidade. Dois registros
 * para o mesmo atendimento passam um de cada vez, e o segundo já enxerga o
 * pagamento do primeiro; dois registros do mesmo cliente com o prêmio também,
 * e o segundo já conta o prêmio que o primeiro usou. No MySQL, a foto do banco
 * que a transação enxerga nasce na primeira leitura sem trava, por isso
 * nenhuma leitura sem trava entra antes das travas (a mesma regra do
 * AppointmentScheduler). O unique de appointment_id no banco é a última
 * barreira: se mesmo assim um segundo pagamento chegar ao insert, a violação
 * vira o mesmo 422, nunca um 500. A consulta de pagamento existente fica sem
 * trava de propósito: no MySQL, uma busca com trava por um appointment_id que
 * ainda não existe trava o intervalo do índice, e registros de atendimentos
 * diferentes esperariam um pelo outro. O PaymentConcurrencyTest e o
 * LoyaltyConcurrencyTest conferem as travas no MySQL (composer test:mysql).
 *
 * A conferência do cliente excluído também vem depois das travas (as do
 * cliente e do agendamento no registro e a do pagamento na correção). A
 * exclusão do cliente trava essas linhas antes de apagar as observações
 * (Client::anonymizeAndDelete()): o pedido que esperou por ela já enxerga o
 * cliente excluído e recusa as observações.
 */
class PaymentRecorder
{
    /**
     * Se o MySQL desfizer a transação por deadlock, o Laravel tenta de novo, até
     * este número de vezes.
     */
    private const ATTEMPTS = 3;

    private const ALREADY_PAID = 'Este atendimento já tem pagamento registrado.';

    /**
     * Recusa do amount enviado junto com o prêmio da fidelidade (422 em amount).
     */
    public const AMOUNT_WITH_REWARD = 'Com o prêmio de fidelidade, o valor é calculado pelo desconto: não envie o valor.';

    public function __construct(private readonly LoyaltyService $loyalty) {}

    /**
     * Registra o pagamento do atendimento. $appointment é o agendamento que o
     * controller autorizou; o cliente e a linha do agendamento são lidos de
     * novo, travados, antes das regras.
     *
     * @param  array{amount?: int|float|string|null, method: string, status?: string|null, notes?: string|null, use_loyalty_reward?: bool|int|string|null}  $data
     *
     * @throws ValidationException
     * @throws ConflictHttpException
     */
    public function record(Appointment $appointment, array $data): Payment
    {
        $useReward = filter_var($data['use_loyalty_reward'] ?? false, FILTER_VALIDATE_BOOL);

        if ($useReward && isset($data['amount'])) {
            throw ValidationException::withMessages(['amount' => self::AMOUNT_WITH_REWARD]);
        }

        try {
            return DB::transaction(function () use ($appointment, $data, $useReward) {
                // O cliente antes do agendamento, na ordem global das travas. O client_id não muda
                // num agendamento (remarcar não troca o cliente), então o lido antes da trava serve.
                $client = Client::withTrashed()->lockForUpdate()->findOrFail($appointment->client_id);
                $current = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

                // A policy autorizou pelo barbeiro lido antes da trava. Um atendimento concluído não
                // muda mais de barbeiro, mas um que mudou no meio do caminho não passa.
                if ($current->barber_id !== $appointment->barber_id || $current->client_id !== $client->id) {
                    throw new ConflictHttpException('O agendamento mudou enquanto você registrava o pagamento. Recarregue e tente de novo.');
                }

                if ($current->status !== AppointmentStatus::Completed) {
                    throw ValidationException::withMessages([
                        'appointment_id' => 'Só é possível registrar o pagamento de um atendimento concluído.',
                    ]);
                }

                if ($current->payment()->exists()) {
                    throw ValidationException::withMessages(['appointment_id' => self::ALREADY_PAID]);
                }

                $this->ensureNotesAllowed($current->id, $data['notes'] ?? null);

                $status = isset($data['status']) ? PaymentStatus::from($data['status']) : PaymentStatus::Paid;

                // Sem valor, vale o preço copiado no agendamento (o do serviço no dia em que foi marcado).
                $amounts = ['amount' => $data['amount'] ?? $current->price, 'discount' => 0];

                if ($useReward) {
                    $amounts = $this->rewardAmounts($client, $current);
                }

                return Payment::create([
                    'appointment_id' => $current->id,
                    'amount' => $amounts['amount'],
                    'discount' => $amounts['discount'],
                    'loyalty_reward' => $useReward,
                    'method' => $data['method'],
                    'status' => $status,
                    'paid_at' => $status === PaymentStatus::Paid ? now() : null,
                    'notes' => $data['notes'] ?? null,
                ]);
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['appointment_id' => self::ALREADY_PAID]);
        }
    }

    /**
     * Valor e desconto do pagamento com o prêmio. Roda depois das travas do
     * cliente e do agendamento: a conta dos prêmios já enxerga o prêmio que
     * outro pagamento do mesmo cliente acabou de usar. O atendimento que está
     * sendo pago fica fora das visitas.
     *
     * @return array{discount: string, amount: string}
     *
     * @throws ValidationException
     */
    private function rewardAmounts(Client $client, Appointment $appointment): array
    {
        $loyalty = $this->loyalty->summary($client, payingAppointmentId: $appointment->id);

        if ($loyalty['available_rewards'] < 1) {
            throw ValidationException::withMessages(['use_loyalty_reward' => $this->loyalty->noRewardMessage($loyalty)]);
        }

        return $this->loyalty->rewardAmounts($appointment->price);
    }

    /**
     * Correção do admin: valor, forma e observações. O agendamento, o status e o
     * paid_at não mudam aqui. Relê a linha travada antes de conferir o cliente:
     * uma correção que esperou a exclusão dele também recusa as observações.
     *
     * @param  array{amount?: int|float|string, method?: string, notes?: string|null}  $changes
     *
     * @throws ValidationException
     */
    public function correct(Payment $payment, array $changes): Payment
    {
        return DB::transaction(function () use ($payment, $changes) {
            $current = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $this->ensureNotesAllowed($current->appointment_id, $changes['notes'] ?? null);

            $current->update($changes);

            return $current;
        }, self::ATTEMPTS);
    }

    /**
     * Muda o status seguindo as transições do PaymentStatus. Ao virar pago, o
     * paid_at recebe o now(); o estorno mantém a data em que foi pago.
     *
     * @throws ValidationException
     */
    public function changeStatus(Payment $payment, PaymentStatus $status): Payment
    {
        return DB::transaction(function () use ($payment, $status) {
            // Relê a linha travada: dois pedidos ao mesmo tempo não pulam as transições.
            $current = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if (! $current->status->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'status' => "Não é possível mudar de {$current->status->label()} para {$status->label()}.",
                ]);
            }

            $current->status = $status;

            if ($status === PaymentStatus::Paid) {
                $current->paid_at = now();
            }

            $current->save();

            return $current;
        }, self::ATTEMPTS);
    }

    /**
     * Recusa observações no pagamento de um cliente excluído. Leitura sem trava,
     * feita depois da trava: no MySQL, a foto do banco nasce aqui (ou na
     * conferência do pagamento que já existe), depois de a exclusão que o
     * pedido esperou terminar.
     *
     * @throws ValidationException
     */
    private function ensureNotesAllowed(int $appointmentId, ?string $notes): void
    {
        if (blank($notes)) {
            return;
        }

        $clientDeleted = Appointment::query()
            ->whereKey($appointmentId)
            ->whereHas('client', fn (Builder $query) => $query->onlyTrashed())
            ->exists();

        if ($clientDeleted) {
            throw ValidationException::withMessages(['notes' => Client::NOTES_AFTER_DELETION]);
        }
    }
}
