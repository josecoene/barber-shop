<?php

namespace App\Enums;

/**
 * Situação do agendamento (coluna appointments.status). As mudanças permitidas
 * ficam todas aqui, em transitions():
 *
 * - agendado vai para confirmado, em atendimento, cancelado ou faltou;
 * - confirmado vai para em atendimento, cancelado ou faltou;
 * - em atendimento vai para concluído;
 * - concluído, cancelado e faltou são finais.
 *
 * Em atendimento, concluído e faltou só valem depois do horário de início
 * (requiresStartedAppointment()).
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * Nome do status para mostrar na tela.
     */
    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendado',
            self::Confirmed => 'Confirmado',
            self::InProgress => 'Em atendimento',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
            self::NoShow => 'Faltou',
        };
    }

    /**
     * Para quais status o agendamento pode ir a partir deste.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Confirmed, self::InProgress, self::Cancelled, self::NoShow],
            self::Confirmed => [self::InProgress, self::Cancelled, self::NoShow],
            self::InProgress => [self::Completed],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->transitions(), true);
    }

    /**
     * Status que só valem depois do horário de início: antes dele o atendimento
     * não começou, não terminou e ninguém faltou ainda.
     */
    public function requiresStartedAppointment(): bool
    {
        return in_array($this, [self::InProgress, self::Completed, self::NoShow], true);
    }

    /**
     * Agendado e confirmado ainda podem ser remarcados.
     */
    public function canBeRescheduled(): bool
    {
        return in_array($this, [self::Scheduled, self::Confirmed], true);
    }

    /**
     * Status que ocupam o horário na agenda do barbeiro e na do cliente: todos,
     * menos cancelado e faltou.
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::Scheduled, self::Confirmed, self::InProgress, self::Completed];
    }

    /**
     * Status de quem ainda vai ser atendido ou está sendo atendido agora.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Scheduled, self::Confirmed, self::InProgress];
    }

    /**
     * As regras de cada status para as telas (o pages.js lê de um data-attribute),
     * para elas não repetirem a tabela de transições:
     * - label: o nome em português;
     * - transitions: para quais status pode ir;
     * - requires_start: só vale depois do horário de início;
     * - reschedulable: ainda pode ser remarcado;
     * - occupies_time: ocupa o horário na agenda;
     * - active: ainda vai ser atendido ou está sendo atendido.
     *
     * @return array<string, array{label: string, transitions: list<string>, requires_start: bool, reschedulable: bool, occupies_time: bool, active: bool}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::cases() as $status) {
            $catalog[$status->value] = [
                'label' => $status->label(),
                'transitions' => array_map(fn (self $next): string => $next->value, $status->transitions()),
                'requires_start' => $status->requiresStartedAppointment(),
                'reschedulable' => $status->canBeRescheduled(),
                'occupies_time' => in_array($status, self::occupying(), true),
                'active' => in_array($status, self::active(), true),
            ];
        }

        return $catalog;
    }
}
