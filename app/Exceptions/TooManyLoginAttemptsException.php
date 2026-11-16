<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Login bloqueado depois de muitas senhas erradas (App\Services\Auth\LoginAttempt).
 *
 * Continua sendo um erro de validação, com status 429: nas telas, a mensagem
 * aparece no campo e-mail, como nas outras recusas do login. Na API, o
 * bootstrap/app.php devolve o mesmo corpo de erro de validação com os cabeçalhos
 * dos outros 429 (headers()), para quem chama saber quando tentar de novo.
 */
class TooManyLoginAttemptsException extends ValidationException
{
    /**
     * Segundos até o login liberar.
     */
    public int $retryAfter = 0;

    /**
     * Recusas aceitas por minuto antes do bloqueio.
     */
    public int $maxAttempts = 0;

    public static function make(int $retryAfter, int $maxAttempts): self
    {
        $exception = self::withMessages([
            'email' => __('auth.throttle', ['seconds' => $retryAfter, 'minutes' => ceil($retryAfter / 60)]),
        ]);

        $exception->status(429);
        $exception->retryAfter = $retryAfter;
        $exception->maxAttempts = $maxAttempts;

        return $exception;
    }

    /**
     * Cabeçalhos do 429 na API. O X-RateLimit-Remaining 0 também faz o throttle:login
     * e o throttle:api manterem estes números, em vez de pôr os deles, que ainda têm folga.
     *
     * @return array<string, int>
     */
    public function headers(): array
    {
        return [
            'Retry-After' => $this->retryAfter,
            'X-RateLimit-Limit' => $this->maxAttempts,
            'X-RateLimit-Remaining' => 0,
        ];
    }
}
