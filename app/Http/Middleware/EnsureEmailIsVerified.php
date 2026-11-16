<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as BaseEnsureEmailIsVerified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware verified: só passa quem já confirmou o e-mail.
 *
 * Igual ao do Laravel, com duas diferenças: a resposta da API sai em pt-BR, e
 * qualquer chamada a /api recebe JSON (o do Laravel só olha o cabeçalho Accept e
 * mandaria o Postman para a tela de aviso).
 */
class EnsureEmailIsVerified extends BaseEnsureEmailIsVerified
{
    /**
     * @param  string|null  $redirectToRoute
     */
    public function handle($request, Closure $next, $redirectToRoute = null): Response
    {
        $user = $request->user();

        if ($user !== null && (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail())) {
            return $next($request);
        }

        if ($this->wantsJson($request)) {
            return response()->json(['message' => 'Confirme seu e-mail para continuar.'], 403);
        }

        return redirect()->guest(route($redirectToRoute ?: 'verification.notice'));
    }

    private function wantsJson(Request $request): bool
    {
        return $request->is('api', 'api/*') || $request->expectsJson();
    }
}
