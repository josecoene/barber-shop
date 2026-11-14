<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Horário de um cliente com um barbeiro para um serviço. Não tem exclusão:
 * cancelar é mudar o status. As regras da agenda (expediente, sobreposição,
 * transições de status) ficam no App\Services\AppointmentScheduler.
 *
 * date sai como Y-m-d, start_time e end_time como H:i:s, e price é a cópia do
 * preço do serviço no momento do agendamento.
 */
class Appointment extends Model
{
    /**
     * O que o JSON do agendamento traz junto (ver summarizeRelations()): o
     * cliente, o barbeiro e o serviço só com id e nome, e o pagamento só com id
     * e status (null quando ainda não há pagamento).
     */
    public const SUMMARY_RELATIONS = ['client:id,name', 'barber:id,name', 'service:id,name', 'payment:id,appointment_id,status'];

    protected $fillable = [
        'client_id',
        'barber_id',
        'service_id',
        'date',
        'start_time',
        'end_time',
        'status',
        'price',
        'notes',
    ];

    /**
     * Padrão do banco repetido aqui para o JSON do POST já sair com o status.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'scheduled',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['status_label'];

    /**
     * price sai sempre como texto com 2 casas ("35.00"), igual em SQLite e MySQL.
     * O date vira Carbon no PHP e sai como Y-m-d no JSON; no banco fica sempre
     * o texto Y-m-d (ver date()). Os ids ficam sempre inteiros, porque a policy
     * e o AppointmentScheduler comparam com ===.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'barber_id' => 'integer',
            'service_id' => 'integer',
            'date' => 'date:Y-m-d',
            'price' => 'decimal:2',
            'status' => AppointmentStatus::class,
        ];
    }

    /**
     * withTrashed: o histórico continua mostrando o cliente depois de excluído
     * (anonimizado). O mesmo vale para o barbeiro e o serviço.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Barber, $this>
     */
    public function barber(): BelongsTo
    {
        return $this->belongsTo(Barber::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    /**
     * Pagamento do atendimento: só o concluído tem, e no máximo um
     * (App\Services\PaymentRecorder).
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Nome do status em português, para a tela não precisar repetir a tabela.
     *
     * @return Attribute<string|null, never>
     */
    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->status?->label());
    }

    /**
     * Grava sempre Y-m-d, mesmo recebendo uma data do Carbon. Sem isso o SQLite
     * guardaria "2026-11-16 00:00:00", e as consultas por dia não achariam a linha.
     *
     * @return Attribute<never, mixed>
     */
    protected function date(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value);
    }

    /**
     * @return Attribute<never, string|null>
     */
    protected function startTime(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : self::normalizeTime($value));
    }

    /**
     * @return Attribute<never, string|null>
     */
    protected function endTime(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : self::normalizeTime($value));
    }

    /**
     * Horário sempre como H:i:s, o formato do banco: "10:00" vira "10:00:00".
     */
    public static function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? "{$time}:00" : $time;
    }

    /**
     * Data e hora de início, no fuso do APP_TIMEZONE.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->date->format('Y-m-d').' '.$this->start_time);
    }

    /**
     * true quando o horário de início já chegou (now() do PHP, nunca o relógio do banco).
     */
    public function hasStarted(): bool
    {
        return $this->startsAt()->lessThanOrEqualTo(now());
    }

    /**
     * Deixa no JSON só o id e o nome do cliente, do barbeiro e do serviço já
     * carregados: pelo agendamento ninguém vê e-mail, telefone ou se o outro tem
     * login. Do pagamento, só o id e o status (com o nome em português), para a
     * tela mostrar se o atendimento está pago; valor e forma ficam no
     * GET /api/payments. Não carrega nada; use junto com SUMMARY_RELATIONS.
     */
    public function summarizeRelations(): static
    {
        foreach (['client', 'barber', 'service'] as $relation) {
            if ($this->relationLoaded($relation)) {
                $this->getRelation($relation)?->setVisible(['id', 'name']);
            }
        }

        if ($this->relationLoaded('payment')) {
            $this->getRelation('payment')?->setVisible(['id', 'status', 'status_label']);
        }

        return $this;
    }

    /**
     * Só os agendamentos que o usuário pode ver, a mesma regra da
     * AppointmentPolicy::view(): o admin vê todos, o barbeiro os da agenda dele
     * e o cliente os próprios. Usuário sem perfil não vê nenhum.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $ownBarberId = $user->isBarber() ? $user->barber?->id : null;
        $ownClientId = $user->isClient() ? $user->client?->id : null;

        match (true) {
            $ownBarberId !== null => $query->where('barber_id', $ownBarberId),
            $ownClientId !== null => $query->where('client_id', $ownClientId),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Agendamentos de hoje em diante que ainda vão acontecer ou estão
     * acontecendo (agendado, confirmado ou em atendimento).
     *
     * @param  Builder<self>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('date', '>=', today()->toDateString())
            ->whereIn('status', AppointmentStatus::active());
    }

    /**
     * Atendimentos concluídos ainda sem pagamento registrado (filtro
     * without_payment do GET /api/appointments). Pagamento pendente ou
     * estornado também conta como registrado: cada atendimento tem no máximo um.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingPayment(Builder $query): void
    {
        $query->where('status', AppointmentStatus::Completed)
            ->whereDoesntHave('payment');
    }
}
