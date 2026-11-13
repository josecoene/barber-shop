<?php

namespace App\Enums;

/**
 * Perfil de quem entra no sistema. Fica na coluna users.role, que não tem
 * padrão: usuário sem perfil não entra (telas e API).
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Barber = 'barber';
    case Client = 'client';

    /**
     * Nome do perfil para mostrar na tela.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Barber => 'Barbeiro',
            self::Client => 'Cliente',
        };
    }
}
