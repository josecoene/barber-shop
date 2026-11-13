<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Validation\Rules\Password;

/**
 * Cadastro de conta de cliente, pelas telas (/register) e pela API (/api/register).
 *
 * A conta nasce com o perfil client e o e-mail ainda não confirmado. Ela só é
 * ligada a um cadastro de cliente (tabela clients) quando o dono confirma o
 * e-mail (App\Listeners\LinkClientToVerifiedUser). Assim ninguém vê o histórico
 * de outra pessoa só por digitar o e-mail dela no cadastro.
 */
class ClientRegistration
{
    /**
     * Regras de validação do cadastro. O domínio barberflow.invalid é reservado
     * para o e-mail de cadastros excluídos e nunca recebe mensagem.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'not_regex:/@barberflow\.invalid$/i', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Cria a conta e dispara o Registered. O Laravel responde a esse evento
     * mandando o e-mail de confirmação, porque o User implementa MustVerifyEmail.
     *
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $data
     */
    public function register(array $data): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $data['phone'] ?? null,
        ]);

        // O perfil não vem da requisição: é sempre client.
        $user->forceFill(['role' => UserRole::Client])->save();

        event(new Registered($user));

        return $user;
    }
}
