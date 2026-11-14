<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Regras da agenda, usadas ao agendar (store), remarcar (update), mudar o
 * status e listar os horários livres do barbeiro:
 *
 * - o horário de término vem do servidor: início + duração do serviço;
 * - só em dia de expediente, começando e terminando dentro dele, com o início
 *   numa fatia (de BUSINESS_SLOT_MINUTES em BUSINESS_SLOT_MINUTES minutos a
 *   partir da abertura). Os valores ficam em config/barberflow.php;
 * - nada no passado e nada depois de BOOKING_DAYS_AHEAD dias a partir de hoje.
 *   O "agora" é o now() do PHP, no fuso do APP_TIMEZONE, e nunca o relógio do banco;
 * - serviço ativo; cliente, barbeiro e serviço não excluídos;
 * - sem sobreposição [início, fim) na agenda do barbeiro nem na do cliente.
 *   Cancelado e faltou não ocupam o horário, e um atendimento pode começar na
 *   hora em que o outro termina;
 * - o cliente que agenda pela conta dele tem no máximo
 *   CLIENT_MAX_ACTIVE_APPOINTMENTS agendamentos ativos de hoje em diante.
 *
 * Os erros saem como 422 de validação, no campo do formulário.
 *
 * Concorrência: agendar e remarcar rodam numa transação que trava primeiro o
 * barbeiro e depois o cliente, sempre nessa ordem (assim dois pedidos nunca
 * esperam um pelo outro em círculo). Dois pedidos para o mesmo barbeiro ou o
 * mesmo cliente passam um de cada vez, e o segundo já enxerga o agendamento do
 * primeiro, porque as consultas sem trava (o limite do cliente e a
 * sobreposição) vêm depois de todas as travas. No MySQL, a foto do banco que
 * a transação enxerga nasce na primeira leitura sem trava: nenhuma leitura sem
 * trava pode entrar antes das travas. A sobreposição fica sem lockForUpdate de
 * propósito: com ele, o MySQL trava também o intervalo do índice, e dois
 * agendamentos de barbeiros e clientes diferentes no mesmo dia esperam um pelo
 * outro até o MySQL derrubar um deles por deadlock. No SQLite dos testes as
 * travas não fazem nada; o AppointmentConcurrencyTest confere tudo isso no
 * MySQL (composer test:mysql).
 */
class AppointmentScheduler
{
    /**
     * Se o MySQL desfizer a transação por deadlock, o Laravel tenta de novo, até
     * este número de vezes.
     */
    private const ATTEMPTS = 3;

    /**
     * Cria o agendamento com o término calculado e o preço copiado do serviço.
     * Pedido do cliente ($byClient) também respeita o limite de agendamentos
     * ativos dele (config('barberflow.client_max_active_appointments')).
     *
     * @param  array{client_id: int|string, barber_id: int|string, service_id: int|string, date: string, start_time: string, notes?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function schedule(array $data, bool $byClient = false): Appointment
    {
        return DB::transaction(function () use ($data, $byClient) {
            $barber = $this->lockBarber((int) $data['barber_id']);
            $client = $this->lockClient((int) $data['client_id']);
            $service = $this->lockService((int) $data['service_id']);
            $startTime = Appointment::normalizeTime($data['start_time']);

            if ($byClient) {
                $this->ensureClientCanBookMore($client);
            }

            $endTime = $this->ensureBookable($barber, $client, $service, $data['date'], $startTime);

            return Appointment::create([
                'client_id' => $client->id,
                'barber_id' => $barber->id,
                'service_id' => $service->id,
                'date' => $data['date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                // Cópia: mudar o preço do serviço depois não muda este agendamento.
                'price' => $service->price,
                'notes' => $data['notes'] ?? null,
            ]);
        }, self::ATTEMPTS);
    }

    /**
     * Remarca (data, horário, barbeiro ou serviço) e muda as observações, só
     * com o status agendado ou confirmado. Se a data, o horário, o barbeiro ou
     * o serviço mudam, as regras rodam de novo sem contar o próprio agendamento
     * e o término é recalculado. Trocar o serviço também troca o preço; sem
     * troca de serviço, o preço copiado no agendamento continua. Agendamento de
     * cliente excluído não recebe observação nova (Client::NOTES_AFTER_DELETION).
     *
     * @param  array{barber_id?: int|string, service_id?: int|string, date?: string, start_time?: string, notes?: string|null}  $changes
     *
     * @throws ValidationException
     * @throws ConflictHttpException
     */
    public function reschedule(Appointment $appointment, array $changes): Appointment
    {
        return DB::transaction(function () use ($appointment, $changes) {
            // A mesma ordem do schedule(): barbeiro, cliente e serviço, e só depois o próprio agendamento.
            $barber = $this->lockBarber((int) ($changes['barber_id'] ?? $appointment->barber_id));
            $client = $this->lockClient($appointment->client_id);
            $service = $this->lockService((int) ($changes['service_id'] ?? $appointment->service_id));

            $current = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

            // Outro pedido trocou o barbeiro ou o serviço depois da leitura feita para travar a linha certa.
            $targetBarberId = (int) ($changes['barber_id'] ?? $current->barber_id);
            $targetServiceId = (int) ($changes['service_id'] ?? $current->service_id);

            if ($targetBarberId !== $barber->id || $targetServiceId !== $service->id) {
                throw new ConflictHttpException('O agendamento mudou enquanto você editava. Recarregue e tente de novo.');
            }

            if (! $current->status->canBeRescheduled()) {
                throw ValidationException::withMessages([
                    'status' => 'Só é possível remarcar um agendamento com status Agendado ou Confirmado.',
                ]);
            }

            $date = $changes['date'] ?? $current->date->format('Y-m-d');
            $startTime = isset($changes['start_time']) ? Appointment::normalizeTime($changes['start_time']) : $current->start_time;
            $serviceChanged = $service->id !== $current->service_id;

            $moved = $serviceChanged
                || $barber->id !== $current->barber_id
                || $date !== $current->date->format('Y-m-d')
                || $startTime !== $current->start_time;

            if ($moved) {
                $current->fill([
                    'barber_id' => $barber->id,
                    'service_id' => $service->id,
                    'date' => $date,
                    'start_time' => $startTime,
                    'end_time' => $this->ensureBookable($barber, $client, $service, $date, $startTime, $current),
                ]);

                if ($serviceChanged) {
                    $current->price = $service->price;
                }
            }

            if (array_key_exists('notes', $changes)) {
                // A exclusão do cliente apagou as observações (LGPD) e não volta a apagar: nada de texto
                // novo depois dela. O cliente foi travado acima, então a exclusão que este pedido esperou já aparece.
                if (filled($changes['notes']) && $client->trashed()) {
                    throw ValidationException::withMessages(['notes' => Client::NOTES_AFTER_DELETION]);
                }

                $current->notes = $changes['notes'];
            }

            $current->save();

            return $current;
        }, self::ATTEMPTS);
    }

    /**
     * Muda o status seguindo as transições do AppointmentStatus. Em
     * atendimento, concluído e faltou só depois do horário de início. Pedido do
     * cliente ($byClient) só cancela até config('barberflow.client_cancel_min_hours')
     * horas antes do início.
     *
     * @throws ValidationException
     */
    public function changeStatus(Appointment $appointment, AppointmentStatus $status, bool $byClient = false): Appointment
    {
        return DB::transaction(function () use ($appointment, $status, $byClient) {
            // Relê a linha travada: dois pedidos ao mesmo tempo não pulam as transições.
            $current = Appointment::query()->lockForUpdate()->findOrFail($appointment->getKey());

            if (! $current->status->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'status' => "Não é possível mudar de {$current->status->label()} para {$status->label()}.",
                ]);
            }

            if ($status->requiresStartedAppointment() && ! $current->hasStarted()) {
                throw ValidationException::withMessages([
                    'status' => "Só é possível mudar para {$status->label()} depois do horário de início.",
                ]);
            }

            if ($byClient && $status === AppointmentStatus::Cancelled && ($error = $this->clientCancelError($current)) !== null) {
                throw ValidationException::withMessages(['status' => $error]);
            }

            $current->update(['status' => $status]);

            return $current;
        }, self::ATTEMPTS);
    }

    /**
     * Inícios livres (H:i) na agenda do barbeiro no dia, para a duração do
     * serviço: dentro do expediente, sem os horários que já passaram e sem
     * sobrepor os agendamentos do barbeiro. Dia sem expediente, que já passou
     * ou depois do limite de dias para agendar devolve a lista vazia. Com
     * $ignore (o agendamento que está sendo remarcado), o horário dele conta
     * como livre, como no reschedule().
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function availableSlots(Barber $barber, Service $service, string $date, ?Appointment $ignore = null): array
    {
        if (($error = $this->serviceError($service)) !== null) {
            throw ValidationException::withMessages(['service_id' => $error]);
        }

        $hours = $this->businessHours();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if (! in_array($day->dayOfWeekIso, $hours['days'], true) || $date < today()->toDateString() || $date > $this->lastBookableDate()) {
            return [];
        }

        $busy = Appointment::query()
            ->where('barber_id', $barber->id)
            ->where('date', $date)
            ->whereIn('status', AppointmentStatus::occupying())
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore->getKey()))
            ->get(['start_time', 'end_time'])
            ->map(fn (Appointment $appointment) => [$this->minutes($appointment->start_time), $this->minutes($appointment->end_time)]);

        $slots = [];

        for ($start = $hours['opening']; $start + $service->duration <= $hours['closing']; $start += $hours['slot']) {
            $end = $start + $service->duration;
            $taken = $busy->contains(fn (array $range) => $range[0] < $end && $range[1] > $start);

            if (! $taken && $this->at($day, $start)->greaterThan(now())) {
                $slots[] = $this->clock($start);
            }
        }

        return $slots;
    }

    /**
     * Confere as regras e devolve o término (H:i:s). Com $ignore, o próprio
     * agendamento fica fora da conta de sobreposição (remarcação).
     *
     * @throws ValidationException
     */
    private function ensureBookable(Barber $barber, Client $client, Service $service, string $date, string $startTime, ?Appointment $ignore = null): string
    {
        $this->throwIfAny($this->recordErrors($barber, $client, $service));
        $this->throwIfAny($this->calendarErrors($date, $startTime, $service->duration));

        $endTime = $this->clock($this->minutes($startTime) + $service->duration).':00';
        $errors = [];

        if ($this->overlapping($date, $startTime, $endTime, $ignore)->where('barber_id', $barber->id)->exists()) {
            $errors['start_time'][] = 'Horário indisponível para este barbeiro.';
        }

        if ($this->overlapping($date, $startTime, $endTime, $ignore)->where('client_id', $client->id)->exists()) {
            $errors['client_id'][] = 'O cliente já tem um horário marcado nesse período.';
        }

        $this->throwIfAny($errors);

        return $endTime;
    }

    /**
     * Cliente, barbeiro ou serviço excluído, e serviço inativo.
     *
     * @return array<string, list<string>>
     */
    private function recordErrors(Barber $barber, Client $client, Service $service): array
    {
        $errors = [];

        if ($client->trashed()) {
            $errors['client_id'][] = 'Este cliente foi excluído.';
        }

        if ($barber->trashed()) {
            $errors['barber_id'][] = 'Este barbeiro foi excluído.';
        }

        if (($error = $this->serviceError($service)) !== null) {
            $errors['service_id'][] = $error;
        }

        return $errors;
    }

    private function serviceError(Service $service): ?string
    {
        if ($service->trashed()) {
            return 'Este serviço foi excluído.';
        }

        if ($service->status !== 'active') {
            return 'Este serviço está inativo e não pode ser agendado.';
        }

        return null;
    }

    /**
     * Dia de expediente, data entre hoje e o limite de dias para agendar,
     * início e fim dentro do expediente, início numa fatia e horário que ainda
     * não passou. Devolve só o primeiro problema encontrado.
     *
     * @return array<string, list<string>>
     */
    private function calendarErrors(string $date, string $startTime, int $duration): array
    {
        $hours = $this->businessHours();
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        $start = $this->minutes($startTime);
        $opening = $this->clock($hours['opening']);
        $closing = $this->clock($hours['closing']);

        if (! in_array($day->dayOfWeekIso, $hours['days'], true)) {
            return ['date' => ['A barbearia não abre nesse dia.']];
        }

        if ($date < today()->toDateString()) {
            return ['date' => ['Essa data já passou.']];
        }

        if ($date > $this->lastBookableDate()) {
            $daysAhead = $this->bookingDaysAhead();
            $last = CarbonImmutable::createFromFormat('!Y-m-d', $this->lastBookableDate())->format('d/m/Y');
            $period = $daysAhead === 1 ? '1 dia' : "{$daysAhead} dias";

            return ['date' => ["Só é possível agendar com até {$period} de antecedência (até {$last})."]];
        }

        if ($start < $hours['opening'] || $start + $duration > $hours['closing']) {
            return ['start_time' => ["O atendimento precisa começar e terminar dentro do expediente, das {$opening} às {$closing}."]];
        }

        // Os segundos também contam: 10:00:30 não está em nenhuma fatia.
        if (($start - $hours['opening']) % $hours['slot'] !== 0 || ! str_ends_with($startTime, ':00')) {
            return ['start_time' => ["Escolha um horário de {$hours['slot']} em {$hours['slot']} minutos, a partir das {$opening}."]];
        }

        if ($this->at($day, $start)->lessThanOrEqualTo(now())) {
            return ['start_time' => ['Esse horário já passou.']];
        }

        return [];
    }

    /**
     * Agendamentos que ocupam algum minuto de [início, fim) no dia: começam
     * antes do fim e terminam depois do início. Leitura sem trava, feita depois
     * das travas do barbeiro e do cliente (ver a concorrência na classe): um
     * lockForUpdate aqui travaria o intervalo do índice no MySQL e faria
     * agendamentos sem conflito esperarem um pelo outro.
     *
     * @return Builder<Appointment>
     */
    private function overlapping(string $date, string $startTime, string $endTime, ?Appointment $ignore): Builder
    {
        return Appointment::query()
            ->where('date', $date)
            ->whereIn('status', AppointmentStatus::occupying())
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->when($ignore !== null, fn (Builder $query) => $query->whereKeyNot($ignore->getKey()));
    }

    /**
     * O cliente que agenda pela conta dele tem no máximo
     * config('barberflow.client_max_active_appointments') agendamentos ativos
     * (agendado, confirmado ou em atendimento) de hoje em diante. Roda depois de
     * travar o cliente: dois pedidos dele ao mesmo tempo passam um de cada vez,
     * e o segundo já conta o primeiro.
     *
     * @throws ValidationException
     */
    private function ensureClientCanBookMore(Client $client): void
    {
        $max = max(1, (int) config('barberflow.client_max_active_appointments'));

        if ($client->appointments()->upcoming()->count() < $max) {
            return;
        }

        $booked = $max === 1 ? '1 horário marcado' : "{$max} horários marcados";

        throw ValidationException::withMessages([
            'client_id' => "Você já tem {$booked}, o máximo para agendar pela sua conta. Cancele um horário para agendar outro, ou fale com a barbearia.",
        ]);
    }

    /**
     * null quando o cliente ainda pode cancelar: até N horas antes do início.
     */
    private function clientCancelError(Appointment $appointment): ?string
    {
        $hours = (int) config('barberflow.client_cancel_min_hours');

        if (now()->lessThanOrEqualTo($appointment->startsAt()->subHours($hours))) {
            return null;
        }

        return $hours === 1
            ? 'Cancelamento só até 1 hora antes do horário.'
            : "Cancelamento só até {$hours} horas antes do horário.";
    }

    /**
     * Trava para escrita: dois agendamentos do mesmo barbeiro passam um de cada vez.
     */
    private function lockBarber(int $id): Barber
    {
        return Barber::withTrashed()->lockForUpdate()->findOrFail($id);
    }

    /**
     * Trava para escrita, sempre depois do barbeiro.
     */
    private function lockClient(int $id): Client
    {
        return Client::withTrashed()->lockForUpdate()->findOrFail($id);
    }

    /**
     * Trava compartilhada: o serviço não muda aqui, mas a exclusão dele (que
     * trava a linha para escrita) espera este agendamento terminar.
     */
    private function lockService(int $id): Service
    {
        return Service::withTrashed()->sharedLock()->findOrFail($id);
    }

    /**
     * Até quantos dias depois de hoje dá para agendar (config('barberflow.booking_days_ahead')).
     */
    private function bookingDaysAhead(): int
    {
        return max(0, (int) config('barberflow.booking_days_ahead'));
    }

    /**
     * Última data aceita para agendar ou remarcar (Y-m-d): hoje mais o limite de dias.
     */
    private function lastBookableDate(): string
    {
        return today()->addDays($this->bookingDaysAhead())->toDateString();
    }

    /**
     * Expediente da config em minutos desde a meia-noite. Também é o que o
     * dashboard usa para calcular a ocupação (App\Services\DashboardMetrics).
     *
     * @return array{opening: int, closing: int, slot: int, days: list<int>}
     */
    public function businessHours(): array
    {
        return [
            'opening' => $this->minutes((string) config('barberflow.business.opening')),
            'closing' => $this->minutes((string) config('barberflow.business.closing')),
            'slot' => max(1, (int) config('barberflow.business.slot_minutes')),
            'days' => array_map('intval', (array) config('barberflow.business.days')),
        ];
    }

    /**
     * "09:30" ou "09:30:00" em minutos desde a meia-noite (570).
     */
    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [1 => 0]);

        return $hours * 60 + $minutes;
    }

    /**
     * Minutos desde a meia-noite em H:i (570 vira "09:30").
     */
    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Data e hora (minutos desde a meia-noite) num só instante, no fuso do app.
     */
    private function at(CarbonImmutable $day, int $minutes): CarbonImmutable
    {
        return $day->setTime(intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @param  array<string, list<string>>  $errors
     *
     * @throws ValidationException
     */
    private function throwIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
