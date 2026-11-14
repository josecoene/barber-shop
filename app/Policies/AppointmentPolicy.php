<?php

namespace App\Policies;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;

/**
 * Agenda: o admin faz tudo; o barbeiro vê e mexe só nos agendamentos da agenda
 * dele; o cliente agenda para si, vê os próprios e só cancela (até
 * CLIENT_CANCEL_MIN_HOURS horas antes, regra do AppointmentScheduler, que
 * responde 422). O index filtra pela mesma regra do view
 * (Appointment::scopeVisibleTo()).
 */
class AppointmentPolicy
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
        return $this->hasOwnRecord($user);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->owns($user, $appointment);
    }

    /**
     * Barbeiro e cliente com cadastro agendam. Para quem, quem decide é o
     * scheduleFor().
     */
    public function create(User $user): bool
    {
        return $this->hasOwnRecord($user);
    }

    /**
     * Em qual agenda e para qual cliente: o barbeiro só na própria agenda (para
     * qualquer cliente) e o cliente só para si (com qualquer barbeiro). Vale ao
     * agendar e ao trocar o barbeiro numa remarcação.
     */
    public function scheduleFor(User $user, int $barberId, int $clientId): bool
    {
        return match (true) {
            $user->isBarber() => $barberId === $user->barber?->id,
            $user->isClient() => $clientId === $user->client?->id,
            default => false,
        };
    }

    /**
     * Remarcar: o barbeiro, nos próprios. O cliente não remarca: cancela e
     * agenda de novo.
     */
    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isBarber() && $this->owns($user, $appointment);
    }

    /**
     * Mudar o status: o barbeiro, nos próprios; o cliente só cancela os próprios.
     */
    public function changeStatus(User $user, Appointment $appointment, AppointmentStatus $status): bool
    {
        if ($user->isClient()) {
            return $status === AppointmentStatus::Cancelled && $this->owns($user, $appointment);
        }

        return $user->isBarber() && $this->owns($user, $appointment);
    }

    /**
     * true quando o agendamento é da agenda do barbeiro logado ou do cliente logado.
     */
    private function owns(User $user, Appointment $appointment): bool
    {
        return match (true) {
            $user->isBarber() => $user->barber !== null && $appointment->barber_id === $user->barber->id,
            $user->isClient() => $user->client !== null && $appointment->client_id === $user->client->id,
            default => false,
        };
    }

    /**
     * Barbeiro ou cliente com o cadastro ligado ao login.
     */
    private function hasOwnRecord(User $user): bool
    {
        return ($user->isBarber() && $user->barber !== null)
            || ($user->isClient() && $user->client !== null);
    }
}
