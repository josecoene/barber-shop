<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/**
 * Clientes: o admin faz tudo; o barbeiro vê, cria e edita, mas não exclui; o
 * cliente não lista os outros e só vê e edita o próprio cadastro.
 */
class ClientPolicy
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
        return $user->isBarber();
    }

    public function view(User $user, Client $client): bool
    {
        return $user->isBarber() || $this->owns($user, $client);
    }

    public function create(User $user): bool
    {
        return $user->isBarber();
    }

    public function update(User $user, Client $client): bool
    {
        return $user->isBarber() || $this->owns($user, $client);
    }

    /**
     * O cliente edita só nome e telefone do próprio cadastro. O e-mail fica com a
     * barbearia: é por ele que a conta foi ligada ao cadastro.
     */
    public function changeEmail(User $user, Client $client): bool
    {
        return $user->isBarber();
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }

    /**
     * true quando o cadastro é o do próprio cliente logado.
     */
    private function owns(User $user, Client $client): bool
    {
        return $user->isClient() && $client->user()->is($user);
    }
}
