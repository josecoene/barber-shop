<?php

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\User;

/**
 * Pagamentos: o admin faz tudo (registrar, corrigir valor, forma e
 * observações, marcar como pago e estornar); o barbeiro registra e vê os
 * pagamentos dos agendamentos dele e marca como pago um pendente desses
 * agendamentos, mas não corrige nem estorna; o cliente só vê os próprios. O
 * index filtra pela mesma regra do view (Payment::scopeVisibleTo()).
 */
class PaymentPolicy
{
    /**
     * O admin passa em tudo sem chegar aos outros métodos.
     */
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /**
     * Barbeiro e cliente com cadastro listam; o index mostra só os deles.
     */
    public function viewAny(User $user): bool
    {
        return ($user->isBarber() && $user->barber !== null)
            || ($user->isClient() && $user->client !== null);
    }

    /**
     * A mesma regra do agendamento (AppointmentPolicy::view): o barbeiro vê os
     * pagamentos da agenda dele e o cliente, os próprios.
     */
    public function view(User $user, Payment $payment): bool
    {
        return $user->can('view', $payment->appointment);
    }

    /**
     * Barbeiro com cadastro registra. Para qual agendamento, quem decide é o
     * recordFor().
     */
    public function create(User $user): bool
    {
        return $user->isBarber() && $user->barber !== null;
    }

    /**
     * O barbeiro registra só o pagamento dos agendamentos da agenda dele.
     */
    public function recordFor(User $user, Appointment $appointment): bool
    {
        return $this->isBarberOf($user, $appointment);
    }

    /**
     * Corrigir valor, forma e observações: só o admin.
     */
    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    /**
     * Mudar o status: o barbeiro só marca como pago, e só nos agendamentos da
     * agenda dele. O estorno é do admin.
     */
    public function changeStatus(User $user, Payment $payment, PaymentStatus $status): bool
    {
        return $status === PaymentStatus::Paid && $this->isBarberOf($user, $payment->appointment);
    }

    /**
     * true quando o agendamento é da agenda do barbeiro logado.
     */
    private function isBarberOf(User $user, Appointment $appointment): bool
    {
        return $user->isBarber() && $user->barber !== null && $appointment->barber_id === $user->barber->id;
    }
}
