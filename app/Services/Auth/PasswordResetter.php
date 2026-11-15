<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * "Esqueci minha senha", pelas telas e pela API, com o broker de senha do Laravel
 * (tabela password_reset_tokens; o link vale 60 minutos e o broker só gera um
 * link novo por e-mail a cada 60 segundos, conforme config/auth.php).
 */
class PasswordResetter
{
    /**
     * Regras do pedido do link.
     *
     * @return array<string, mixed>
     */
    public static function linkRules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * Regras da redefinição: o token do link, o e-mail e a senha nova confirmada.
     *
     * @return array<string, mixed>
     */
    public static function resetRules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ];
    }

    /**
     * Manda o link de redefinição se o e-mail for de algum usuário.
     *
     * A resposta é sempre a mesma: com e-mail cadastrado ou não, quando o link
     * foi pedido há pouco e também quando o servidor de e-mail está fora do ar
     * ou recusa o endereço. Assim ninguém usa esta tela para descobrir quem tem
     * conta: sem isso, a falha no envio, que só acontece com o e-mail
     * cadastrado, viraria um erro 500 só para quem tem conta. A falha vai para o
     * log sem o endereço (bootstrap/app.php), e a pessoa pede o link de novo.
     */
    public function sendLink(string $email): string
    {
        try {
            Password::sendResetLink(['email' => $email]);
        } catch (TransportExceptionInterface $e) {
            report($e);
        }

        return __('passwords.sent');
    }

    /**
     * Troca a senha com o token do link. Também troca o remember_token (o
     * "lembrar de mim" antigo para de valer) e encerra os logins abertos, tokens e
     * sessões, para quem tinha a senha antiga sair.
     *
     * Conta de cliente que ainda não confirmou o e-mail: quem digitou o nome e o
     * telefone no cadastro pode não ser o dono do e-mail (alguém pode ter criado a
     * conta com o e-mail de outra pessoa), e quem redefine acabou de provar que é,
     * pelo link. Por isso o nome digitado é trocado pela parte do e-mail antes do @
     * e o telefone é apagado: nenhum dos dois chega ao cadastro de cliente quando o
     * dono confirmar o e-mail.
     *
     * @param  array{token: string, email: string, password: string}  $credentials
     *
     * @throws ValidationException quando o token não vale (errado, usado ou expirado)
     */
    public function reset(#[\SensitiveParameter] array $credentials): string
    {
        $status = Password::reset($credentials, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ]);

            if ($user->isClient() && ! $user->hasVerifiedEmail()) {
                $user->forceFill([
                    'name' => Str::before($user->email, '@'),
                    'phone' => null,
                ]);
            }

            $user->save();

            $user->revokeLogins();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            // Token errado e e-mail sem conta têm a mesma resposta, para não revelar quem tem conta.
            throw ValidationException::withMessages(['email' => __(Password::INVALID_TOKEN)]);
        }

        return __($status);
    }
}
