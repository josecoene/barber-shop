<?php

namespace App\Enums;

/**
 * Forma de pagamento (coluna payments.method).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';

    /**
     * Nome da forma de pagamento para mostrar na tela.
     */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Pix => 'Pix',
            self::CreditCard => 'Cartão de crédito',
            self::DebitCard => 'Cartão de débito',
        };
    }

    /**
     * As formas de pagamento para as telas (o pages.js lê de um
     * data-attribute), no mesmo formato do AppointmentStatus::catalog():
     * - label: o nome em português.
     *
     * @return array<string, array{label: string}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::cases() as $method) {
            $catalog[$method->value] = ['label' => $method->label()];
        }

        return $catalog;
    }
}
