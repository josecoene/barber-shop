<?php

namespace App\Policies;

use App\Models\Barber;
use App\Models\User;

/**
 * Barbeiros: o admin faz tudo (inclusive criar e redefinir o login); o barbeiro
 * vê; o cliente vê só o id e o nome, para escolher com quem agendar.
 */
class BarberPolicy
{
    /**
     * O admin passa em tudo sem chegar aos outros métodos.
     */
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isBarber() || $user->isClient();
    }

    public function view(User $user, Barber $barber): bool
    {
        return $user->isBarber() || $user->isClient();
    }

    /**
     * Quem vê e-mail, telefone e se o barbeiro tem login. O cliente não vê, e a
     * busca dele também não olha o e-mail.
     */
    public function viewContactDetails(User $user): bool
    {
        return $user->isBarber();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Barber $barber): bool
    {
        return false;
    }

    public function delete(User $user, Barber $barber): bool
    {
        return false;
    }
}
