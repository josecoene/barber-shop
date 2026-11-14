<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pagamento de um atendimento concluído, no máximo um por agendamento. Não tem
 * exclusão: estornar é mudar o status. As regras (só concluído, um por
 * agendamento, valor padrão, paid_at e transições) ficam no
 * App\Services\PaymentRecorder.
 *
 * amount sai como texto com 2 casas ("35.00"), igual ao price do agendamento,
 * e paid_at é preenchido pelo servidor quando o pagamento vira pago.
 */
class Payment extends Model
{
    /**
     * O agendamento que o JSON do pagamento traz, resumido: data, horário e o
     * cliente, o barbeiro e o serviço só com id e nome (ver summarizeAppointment()).
     */
    public const APPOINTMENT_SUMMARY = [
        'appointment:id,client_id,barber_id,service_id,date,start_time,end_time',
        'appointment.client:id,name',
        'appointment.barber:id,name',
        'appointment.service:id,name',
    ];

    protected $fillable = [
        'appointment_id',
        'amount',
        'method',
        'status',
        'paid_at',
        'notes',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['method_label', 'status_label'];

    /**
     * O appointment_id fica sempre inteiro, porque a policy compara com ===.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appointment_id' => 'integer',
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Nome da forma de pagamento em português.
     *
     * @return Attribute<string|null, never>
     */
    protected function methodLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->method?->label());
    }

    /**
     * Nome do status em português.
     *
     * @return Attribute<string|null, never>
     */
    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->status?->label());
    }

    /**
     * Deixa no JSON só a data, o horário e o cliente, o barbeiro e o serviço
     * (id e nome) do agendamento já carregado: pelo pagamento ninguém vê as
     * observações do agendamento nem o e-mail e o telefone das pessoas. Não
     * carrega nada; use junto com APPOINTMENT_SUMMARY.
     */
    public function summarizeAppointment(): static
    {
        if ($this->relationLoaded('appointment')) {
            $this->appointment?->summarizeRelations()
                ->setVisible(['id', 'date', 'start_time', 'end_time', 'client', 'barber', 'service']);
        }

        return $this;
    }

    /**
     * Só os pagamentos que o usuário pode ver, a mesma regra da
     * PaymentPolicy::view(): o admin vê todos, e os outros, os pagamentos dos
     * agendamentos que veem (Appointment::scopeVisibleTo(): o barbeiro, os da
     * agenda dele; o cliente, os próprios). Usuário sem perfil não vê nenhum.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $query->whereHas('appointment', fn (Builder $appointments) => $appointments->visibleTo($user));
    }
}
