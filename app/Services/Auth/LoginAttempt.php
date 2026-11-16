<?php

namespace App\Services\Auth;

use App\Exceptions\TooManyLoginAttemptsException;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Confere o login (e-mail e senha) com as mesmas regras nas telas e na API:
 * - e-mail ou senha errados: 422 com auth.failed, sem dizer qual dos dois errou;
 * - conta sem acesso (sem perfil, barbeiro sem cadastro ativo, cliente com o
 *   cadastro excluído): 422 com "Esta conta não tem acesso.";
 * - depois de config('barberflow.rate_limits.login') tentativas recusadas no
 *   mesmo minuto, para o mesmo e-mail no mesmo IP: 429 com auth.throttle, até o
 *   minuto passar (na API, com Retry-After). O login certo zera a contagem.
 *
 * Não abre sessão nem cria token: quem chama faz isso com o usuário devolvido.
 * Assim quem é recusado nunca chega a ficar logado.
 */
class LoginAttempt
{
    public const NO_ACCESS_MESSAGE = 'Esta conta não tem acesso.';

    /**
     * @throws ValidationException
     */
    public function authenticate(Request $request, string $email, #[\SensitiveParameter] string $password): User
    {
        $key = $this->throttleKey($request, $email);

        $this->ensureIsNotRateLimited($request, $key);

        $guard = Auth::guard('web');
        $credentials = ['email' => $email, 'password' => $password];

        // O validate() do Laravel leva pelo menos 200 ms quando recusa, para o tempo
        // de resposta não contar se o e-mail existe.
        if (! $guard->validate($credentials)) {
            $this->refuse($key, __('auth.failed'));
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();

        if (! $user->canLogIn()) {
            $this->refuse($key, self::NO_ACCESS_MESSAGE);
        }

        RateLimiter::clear($key);

        // Mesma coisa que o Auth::attempt() faz: refaz o hash se o custo do bcrypt mudou.
        if (config('hashing.rehash_on_login', true)) {
            $guard->getProvider()->rehashPasswordIfRequired($user, $credentials);
        }

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $key, string $message): never
    {
        RateLimiter::hit($key);

        throw ValidationException::withMessages(['email' => $message]);
    }

    /**
     * @throws TooManyLoginAttemptsException
     */
    private function ensureIsNotRateLimited(Request $request, string $key): void
    {
        $maxAttempts = (int) config('barberflow.rate_limits.login');

        if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return;
        }

        event(new Lockout($request));

        // Erro de validação com status 429 e, na API, os cabeçalhos Retry-After e X-RateLimit-*.
        throw TooManyLoginAttemptsException::make(RateLimiter::availableIn($key), $maxAttempts);
    }

    /**
     * Uma contagem por e-mail e IP. Str::transliterate tira acentos, para
     * "jóse@x.com" e "jose@x.com" não ganharem contagens separadas.
     */
    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.Str::transliterate(Str::lower($email).'|'.$request->ip());
    }
}
