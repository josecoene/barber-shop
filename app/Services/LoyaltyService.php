<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Programa de fidelidade. A regra fica toda aqui:
 *
 * - visita é um atendimento concluído do cliente. Não contam como visita o
 *   atendimento pago com o prêmio e, na hora de pagar, o próprio atendimento
 *   que está sendo pago (ele ainda não ajuda a ganhar o prêmio que o pagaria:
 *   a cada LOYALTY_VISITS atendimentos, é o seguinte que sai com o desconto);
 * - a cada LOYALTY_VISITS visitas (config('barberflow.loyalty.visits')), o
 *   cliente ganha um prêmio. Prêmios disponíveis = ganhos - usados, e usado é
 *   o pagamento com loyalty_reward que não foi estornado: o estorno devolve o
 *   prêmio, e o atendimento continua fora das visitas;
 * - progresso = visitas desde o último prêmio ganho, de 0 a LOYALTY_VISITS - 1
 *   (ex.: 7 de 10);
 * - o prêmio dá LOYALTY_DISCOUNT_PERCENT % de desconto no price do agendamento
 *   (rewardAmounts()). Quem aplica é o App\Services\PaymentRecorder, depois de
 *   travar o cliente: dois pagamentos do mesmo cliente ao mesmo tempo não usam
 *   o mesmo prêmio.
 *
 * As contas são count no banco, iguais no SQLite e no MySQL. A listagem de
 * clientes faz as mesmas contas como subconsultas da própria consulta da
 * página (withCounts()), sem uma consulta por cliente.
 */
class LoyaltyService
{
    /**
     * Colunas que o withCounts() acrescenta à consulta de clientes.
     */
    private const VISITS_COLUMN = 'loyalty_visits';

    private const REWARDS_USED_COLUMN = 'loyalty_rewards_used';

    /**
     * A cada quantas visitas o cliente ganha um prêmio (no mínimo 1).
     */
    public function visitsPerReward(): int
    {
        return max(1, (int) config('barberflow.loyalty.visits'));
    }

    /**
     * Desconto do prêmio em %, de 0 a 100.
     */
    public function discountPercent(): int
    {
        return min(100, max(0, (int) config('barberflow.loyalty.discount_percent')));
    }

    /**
     * Fidelidade do cliente. Com $payingAppointmentId (o atendimento que está
     * sendo pago), esse atendimento fica fora das visitas: é a conta que vale
     * para pagá-lo com o prêmio.
     *
     * @return array{visits: int, visits_per_reward: int, progress: int, rewards_earned: int, rewards_used: int, available_rewards: int, discount_percent: int}
     */
    public function summary(Client $client, ?int $payingAppointmentId = null): array
    {
        $visits = $client->appointments();
        $this->onlyVisits($visits);

        if ($payingAppointmentId !== null) {
            $visits->whereKeyNot($payingAppointmentId);
        }

        $rewardsUsed = $client->appointments();
        $this->onlyRewardsUsed($rewardsUsed);

        return $this->fromCounts($visits->count(), $rewardsUsed->count());
    }

    /**
     * Acrescenta à consulta de clientes as duas contas da fidelidade, como
     * subconsultas, para a listagem não fazer uma consulta por cliente. Leia o
     * resultado de cada cliente com attachSummary().
     *
     * @param  Builder<Client>  $clients
     * @return Builder<Client>
     */
    public function withCounts(Builder $clients): Builder
    {
        return $clients->withCount([
            'appointments as '.self::VISITS_COLUMN => fn (Builder $appointments) => $this->onlyVisits($appointments),
            'appointments as '.self::REWARDS_USED_COLUMN => fn (Builder $appointments) => $this->onlyRewardsUsed($appointments),
        ]);
    }

    /**
     * Põe a fidelidade no JSON de um cliente lido com withCounts(), no campo
     * loyalty (o mesmo resumo do summary()), e esconde as duas colunas da conta.
     */
    public function attachSummary(Client $client): Client
    {
        $summary = $this->fromCounts(
            (int) $client->getAttribute(self::VISITS_COLUMN),
            (int) $client->getAttribute(self::REWARDS_USED_COLUMN),
        );

        $client->makeHidden([self::VISITS_COLUMN, self::REWARDS_USED_COLUMN]);
        $client->setAttribute('loyalty', $summary);

        return $client;
    }

    /**
     * Desconto e valor a pagar com o prêmio, em texto com 2 casas ("35.00"):
     * o desconto é price × LOYALTY_DISCOUNT_PERCENT ÷ 100, arredondado no
     * centavo, e o valor é o que sobra do price. A conta é feita em centavos,
     * sem erro de arredondamento do float.
     *
     * @return array{discount: string, amount: string}
     */
    public function rewardAmounts(string|int|float $price): array
    {
        $cents = (int) round((float) $price * 100);
        $discount = (int) round($cents * $this->discountPercent() / 100);

        return [
            'discount' => $this->money($discount),
            'amount' => $this->money($cents - $discount),
        ];
    }

    /**
     * Mensagem da recusa (422) de quem pede o prêmio sem ter um disponível. O
     * resumo é o do pagamento, sem o atendimento que está sendo pago.
     *
     * @param  array{progress: int, visits_per_reward: int}  $summary
     */
    public function noRewardMessage(array $summary): string
    {
        return "O cliente ainda não tem prêmio de fidelidade disponível: {$summary['progress']} de {$summary['visits_per_reward']} visitas, sem contar este atendimento.";
    }

    /**
     * @return array{visits: int, visits_per_reward: int, progress: int, rewards_earned: int, rewards_used: int, available_rewards: int, discount_percent: int}
     */
    private function fromCounts(int $visits, int $rewardsUsed): array
    {
        $perReward = $this->visitsPerReward();
        $earned = intdiv($visits, $perReward);

        return [
            'visits' => $visits,
            'visits_per_reward' => $perReward,
            'progress' => $visits % $perReward,
            'rewards_earned' => $earned,
            'rewards_used' => $rewardsUsed,
            // Nunca negativo, mesmo se o LOYALTY_VISITS subir depois de prêmios já usados.
            'available_rewards' => max(0, $earned - $rewardsUsed),
            'discount_percent' => $this->discountPercent(),
        ];
    }

    /**
     * Visitas: atendimentos concluídos que não foram pagos com o prêmio (nem
     * com o prêmio estornado).
     */
    private function onlyVisits(Builder|Relation $appointments): void
    {
        $appointments->where('appointments.status', AppointmentStatus::Completed)
            ->whereDoesntHave('payment', fn (Builder $payment) => $payment->where('payments.loyalty_reward', true));
    }

    /**
     * Prêmios usados: atendimentos pagos com o prêmio, sem estorno. Cada
     * atendimento tem no máximo um pagamento, então contar os atendimentos é
     * contar os pagamentos.
     */
    private function onlyRewardsUsed(Builder|Relation $appointments): void
    {
        $appointments->whereHas('payment', fn (Builder $payment) => $payment
            ->where('payments.loyalty_reward', true)
            ->where('payments.status', '!=', PaymentStatus::Refunded));
    }

    /**
     * Centavos em texto com 2 casas (3500 vira "35.00").
     */
    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
