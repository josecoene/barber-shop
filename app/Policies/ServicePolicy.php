<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Serviços: o admin faz tudo; o barbeiro só vê; o cliente vê só os ativos.
 */
class ServicePolicy
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

    /**
     * Para o cliente, serviço inativo é como se não existisse: 404, igual a um
     * id que não está no banco.
     */
    public function view(User $user, Service $service): Response|bool
    {
        if ($user->isBarber()) {
            return true;
        }

        if (! $user->isClient()) {
            return false;
        }

        return $service->status === 'active'
            ? Response::allow()
            : Response::denyAsNotFound('Registro não encontrado.');
    }

    /**
     * Quem vê os serviços inativos na lista. Para os outros, a lista mostra só
     * os ativos, seja qual for o filtro de status.
     */
    public function viewInactive(User $user): bool
    {
        return $user->isBarber();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Service $service): bool
    {
        return false;
    }

    public function delete(User $user, Service $service): bool
    {
        return false;
    }
}
