<?php

namespace App\Enums;

/**
 * Situação do pagamento (coluna payments.status). As mudanças permitidas
 * ficam todas aqui, em transitions():
 *
 * - pendente vai para pago;
 * - pago vai para estornado;
 * - estornado é final.
 *
 * Um pagamento nasce pendente ou pago (initial()). Quem pode fazer cada
 * mudança fica na PaymentPolicy: o estorno é só do admin.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Refunded = 'refunded';

    /**
     * Nome do status para mostrar na tela.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Paid => 'Pago',
            self::Refunded => 'Estornado',
        };
    }

    /**
     * Para quais status o pagamento pode ir a partir deste.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid],
            self::Paid => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->transitions(), true);
    }

    /**
     * Status aceitos ao registrar o pagamento. Ninguém registra um pagamento
     * já estornado.
     *
     * @return list<self>
     */
    public static function initial(): array
    {
        return [self::Pending, self::Paid];
    }

    /**
     * As regras de cada status para as telas (o pages.js lê de um
     * data-attribute), no mesmo formato do AppointmentStatus::catalog():
     * - label: o nome em português;
     * - transitions: para quais status pode ir;
     * - initial: aceito ao registrar o pagamento.
     *
     * @return array<string, array{label: string, transitions: list<string>, initial: bool}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::cases() as $status) {
            $catalog[$status->value] = [
                'label' => $status->label(),
                'transitions' => array_map(fn (self $next): string => $next->value, $status->transitions()),
                'initial' => in_array($status, self::initial(), true),
            ];
        }

        return $catalog;
    }
}
