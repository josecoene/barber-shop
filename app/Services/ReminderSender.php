<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Lembrete por e-mail dos agendamentos (App\Mail\AppointmentReminder), mandado
 * na hora, sem fila:
 *
 * - recebe o lembrete o agendamento agendado ou confirmado
 *   (AppointmentStatus::remindable()) que ainda não começou;
 * - o comando appointments:send-reminders (sendDue(), a cada 15 minutos) manda
 *   o lembrete dos agendamentos que começam depois de agora e até
 *   REMINDER_HOURS_BEFORE horas à frente, com o reminder_sent_at nulo. Cada
 *   lembrete sai uma vez; sai de novo só se o agendamento for remarcado para
 *   outra data ou outro horário (o AppointmentScheduler volta o
 *   reminder_sent_at a nulo);
 * - o "Enviar lembrete agora" da agenda (sendNow()) manda na hora, a qualquer
 *   momento antes do início, e conta como o lembrete do agendamento: depois
 *   dele o comando não manda outro. Entre um envio e outro do mesmo
 *   agendamento (do comando ou manual) passam pelo menos
 *   config('barberflow.reminders.resend_minutes') minutos, mesmo que ele seja
 *   remarcado nesse meio tempo; antes disso, 429 com Retry-After. O limite por
 *   usuário (o limitador reminders do AppServiceProvider) fica na rota;
 * - o e-mail não sai para cliente excluído nem para endereço do domínio
 *   barberflow.invalid, o de quem foi excluído (o comando pula; o envio
 *   manual responde 422).
 *
 * Hora: o "agora" é o now() do PHP, no fuso do APP_TIMEZONE. O banco filtra só
 * pelo appointments.date (Y-m-d), e a conta exata do horário é feita em PHP,
 * igual no SQLite e no MySQL.
 *
 * Concorrência: o comando reserva a linha antes de enviar, com um UPDATE que só
 * pega se o reminder_sent_at ainda estiver nulo e o agendamento continuar como
 * foi lido (status, data, horário, barbeiro e serviço): duas rodadas ao mesmo
 * tempo, ou o comando e o envio manual, não mandam o mesmo lembrete duas vezes,
 * e o e-mail fala do horário que estava na linha na hora da reserva. O envio
 * manual trava só a linha do agendamento (a penúltima da ordem global das
 * travas: barbeiro, cliente, serviço, agendamento, pagamento), lê o resto
 * depois da trava e manda o e-mail depois de soltá-la. Se o envio falhar, o
 * reminder_sent_at volta ao que era, para o lembrete ser tentado de novo, mas
 * só enquanto a linha continuar com a própria reserva (ver release()). O
 * ReminderCommandTest e o ReminderApiTest conferem essas condições mudando a
 * linha logo antes do UPDATE da reserva e do que a desfaz.
 *
 * LGPD: o que este serviço grava no log da aplicação leva só o id do
 * agendamento, nunca o nome, o e-mail ou a mensagem do erro (que pode trazer o
 * endereço). O MAIL_MAILER=log do local é outra coisa: ele grava a própria
 * mensagem, com o endereço do cliente, no log (ver docs/API.md#lembretes).
 */
class ReminderSender
{
    /**
     * Se o MySQL desfizer a transação por deadlock, o Laravel tenta de novo, até
     * este número de vezes.
     */
    private const ATTEMPTS = 3;

    /**
     * O que o e-mail mostra e o que decide se ele sai. O cliente vem com
     * withTrashed (relação do Appointment), para o excluído ser reconhecido.
     */
    private const RELATIONS = ['client', 'barber', 'service'];

    /**
     * Manda os lembretes que venceram (comando appointments:send-reminders) e
     * devolve quantos saíram, quantos foram pulados (cliente excluído, e-mail
     * que não recebe mensagem ou lembrete que outro envio pegou antes) e
     * quantos falharam (esses voltam a ser tentados na próxima rodada).
     *
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function sendDue(): array
    {
        $now = now()->toImmutable();
        $until = $now->addHours($this->hoursBefore());
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        // O banco filtra pelo dia; a hora exata fica para o PHP, logo abaixo.
        $candidates = Appointment::query()
            ->whereIn('status', AppointmentStatus::remindable())
            ->whereNull('reminder_sent_at')
            ->whereBetween('date', [$now->toDateString(), $until->toDateString()])
            ->with(self::RELATIONS)
            ->lazyById(100);

        foreach ($candidates as $appointment) {
            $startsAt = $appointment->startsAt();

            // Começa depois de agora (o que já começou não recebe) e até o fim da janela.
            if ($startsAt->lessThanOrEqualTo($now) || $startsAt->greaterThan($until)) {
                continue;
            }

            $result[$this->deliverDue($appointment)]++;
        }

        return $result;
    }

    /**
     * "Enviar lembrete agora": manda o lembrete na hora, grava o
     * reminder_sent_at e devolve o agendamento com ele. Quem pode fica na
     * AppointmentPolicy (sendReminder).
     *
     * @throws ValidationException 422: status sem lembrete, agendamento que já começou, cliente excluído ou e-mail que não recebe mensagem
     * @throws TooManyRequestsHttpException 429: o último lembrete saiu há menos de resend_minutes minutos
     * @throws ServiceUnavailableHttpException 503: o envio do e-mail falhou
     */
    public function sendNow(Appointment $appointment): Appointment
    {
        [$current, $previous, $sentAt] = DB::transaction(function () use ($appointment) {
            // Só a linha do agendamento é travada, e tudo o mais é lido depois da trava: dois cliques
            // ao mesmo tempo passam um de cada vez, e o segundo já enxerga o lembrete do primeiro.
            $current = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());
            $current->load(self::RELATIONS);

            $this->ensureCanSendNow($current);

            $previous = $current->getRawOriginal('reminder_sent_at');
            $sentAt = $current->fromDateTime(now());

            Appointment::query()->whereKey($current->getKey())->toBase()->update(['reminder_sent_at' => $sentAt]);

            $current->setAttribute('reminder_sent_at', $sentAt);
            $current->syncOriginalAttribute('reminder_sent_at');

            return [$current, $previous, $sentAt];
        }, self::ATTEMPTS);

        // O envio manual também fica registrado no cache, por agendamento: a remarcação volta o
        // reminder_sent_at a nulo, mas não apaga este registro, então remarcar não libera outro envio
        // antes de resend_minutes minutos. Se o e-mail não sair, o registro é apagado.
        RateLimiter::hit($this->resendKey($current), $this->resendMinutes() * 60);

        // Fora da transação: a trava não fica presa enquanto o servidor de e-mail responde.
        try {
            $this->mail($current);
        } catch (Throwable) {
            RateLimiter::clear($this->resendKey($current));
            $this->release($current, $sentAt, $previous);

            throw new ServiceUnavailableHttpException(null, 'Não foi possível enviar o lembrete agora. Tente de novo em alguns minutos.');
        }

        return $current;
    }

    /**
     * Um lembrete do comando: confere o destinatário, reserva a linha e manda.
     *
     * @return 'sent'|'skipped'|'failed'
     */
    private function deliverDue(Appointment $appointment): string
    {
        if ($this->recipientError($appointment) !== null) {
            return 'skipped';
        }

        $sentAt = $appointment->fromDateTime(now());

        // Outra rodada ou o envio manual pegou antes, ou o agendamento mudou depois da leitura.
        if (! $this->reserve($appointment, $sentAt)) {
            return 'skipped';
        }

        try {
            $this->mail($appointment);
        } catch (Throwable) {
            $this->release($appointment, $sentAt, null);

            return 'failed';
        }

        return 'sent';
    }

    /**
     * Grava o reminder_sent_at antes do envio (UPDATE ... WHERE id = ? AND
     * reminder_sent_at IS NULL), só se a linha continuar como foi lida. true
     * quando a reserva pegou.
     */
    private function reserve(Appointment $appointment, string $sentAt): bool
    {
        $reserved = Appointment::query()
            ->whereKey($appointment->getKey())
            ->whereNull('reminder_sent_at')
            ->whereIn('status', AppointmentStatus::remindable())
            ->where('date', $appointment->date->format('Y-m-d'))
            ->where('start_time', $appointment->start_time)
            ->where('barber_id', $appointment->barber_id)
            ->where('service_id', $appointment->service_id)
            ->toBase()
            ->update(['reminder_sent_at' => $sentAt]);

        return $reserved === 1;
    }

    /**
     * O envio falhou: o reminder_sent_at volta ao que era (nulo, no comando),
     * para o lembrete ser tentado de novo. Só desfaz a própria reserva: a linha
     * precisa continuar com o reminder_sent_at, a data e o horário da reserva.
     * Se uma remarcação mudou a data ou o horário nesse meio tempo, ela já zerou
     * a linha, e o que estiver lá fica como está: nulo, ou o lembrete do horário
     * novo, mesmo gravado no mesmo segundo da reserva (o reminder_sent_at é
     * gravado em segundos, então ele sozinho não separa os dois). O log leva só
     * o id.
     */
    private function release(Appointment $appointment, string $sentAt, ?string $previous): void
    {
        Appointment::query()
            ->whereKey($appointment->getKey())
            ->where('reminder_sent_at', $sentAt)
            ->where('date', $appointment->date->format('Y-m-d'))
            ->where('start_time', $appointment->start_time)
            ->toBase()
            ->update(['reminder_sent_at' => $previous]);

        Log::warning('Lembrete por e-mail não enviado.', ['appointment_id' => $appointment->getKey()]);
    }

    /**
     * Regras do "Enviar lembrete agora", conferidas na linha travada.
     *
     * @throws ValidationException
     * @throws TooManyRequestsHttpException
     */
    private function ensureCanSendNow(Appointment $appointment): void
    {
        if (! $appointment->status->getsReminder()) {
            throw ValidationException::withMessages([
                'reminder' => 'Só é possível enviar o lembrete de um agendamento com status Agendado ou Confirmado.',
            ]);
        }

        if ($appointment->hasStarted()) {
            throw ValidationException::withMessages([
                'reminder' => 'Este agendamento já começou: não há lembrete para enviar.',
            ]);
        }

        if (($error = $this->recipientError($appointment)) !== null) {
            throw ValidationException::withMessages(['reminder' => $error]);
        }

        $this->ensureResendAllowed($appointment);
    }

    /**
     * Um envio a cada resend_minutes minutos por agendamento. Contam o
     * reminder_sent_at da linha (o último lembrete, do comando ou manual) e o
     * último envio manual, guardado no cache (RateLimiter): a remarcação zera o
     * reminder_sent_at, mas não o cache, então remarcar não libera outro envio
     * antes da hora. Antes disso, 429 com Retry-After em segundos.
     *
     * @throws TooManyRequestsHttpException
     */
    private function ensureResendAllowed(Appointment $appointment): void
    {
        $minutes = $this->resendMinutes();
        $key = $this->resendKey($appointment);

        $seconds = max(
            $appointment->reminder_sent_at === null
                ? 0
                : $appointment->reminder_sent_at->copy()->addMinutes($minutes)->getTimestamp() - now()->getTimestamp(),
            RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0,
        );

        if ($seconds <= 0) {
            return;
        }

        $wait = (int) ceil($seconds / 60);

        throw new TooManyRequestsHttpException(
            $seconds,
            "Um lembrete deste agendamento saiu há menos de {$minutes} minutos. Tente de novo em ".($wait === 1 ? '1 minuto.' : "{$wait} minutos."),
        );
    }

    /**
     * Chave do último envio manual do agendamento no RateLimiter (no cache, fora
     * da linha, para a remarcação não apagá-la).
     */
    private function resendKey(Appointment $appointment): string
    {
        return 'appointment-reminder:'.$appointment->getKey();
    }

    /**
     * null quando o lembrete pode sair para o cliente; senão, o motivo.
     */
    private function recipientError(Appointment $appointment): ?string
    {
        $client = $appointment->client;

        if ($client === null || $client->trashed()) {
            return 'O cliente foi excluído: não há para quem enviar o lembrete.';
        }

        if (blank($client->email) || $client->hasReleasedEmail()) {
            return 'O cadastro do cliente não tem um e-mail que receba mensagens.';
        }

        return null;
    }

    /**
     * Manda o e-mail na hora (o AppointmentReminder não vai para fila).
     */
    private function mail(Appointment $appointment): void
    {
        Mail::to($appointment->client->email)->send(new AppointmentReminder($appointment));
    }

    /**
     * Quantas horas antes do início o comando manda o lembrete (no mínimo 1).
     */
    private function hoursBefore(): int
    {
        return max(1, (int) config('barberflow.reminders.hours_before'));
    }

    /**
     * Minutos entre dois envios do mesmo agendamento (no mínimo 1).
     */
    private function resendMinutes(): int
    {
        return max(1, (int) config('barberflow.reminders.resend_minutes'));
    }
}
