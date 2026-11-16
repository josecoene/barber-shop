<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança em todas as respostas (telas, API e erros).
 *
 * Os cabeçalhos ficam só aqui, na aplicação: o Nginx da imagem de produção não
 * repete nenhum deles.
 */
class SecurityHeaders
{
    /**
     * Política de conteúdo: só arquivos do próprio site, sem script ou estilo
     * inline. As telas trazem o CSS e o JS do build do Vite (public/build), e o
     * JS pode mudar estilos pelo el.style, que a CSP não bloqueia.
     */
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; "
        ."img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; "
        ."base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        // O navegador não tenta adivinhar o tipo do arquivo (um texto não vira script).
        $headers->set('X-Content-Type-Options', 'nosniff');
        // Nenhum site pode abrir o BarberFlow dentro de um iframe (clickjacking).
        $headers->set('X-Frame-Options', 'DENY');
        // Para outros sites vai só a origem. O Sanctum lê o Referer para reconhecer as telas.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Recursos do aparelho que o sistema não usa ficam desligados.
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // Uma janela aberta por outro site não acessa a do BarberFlow.
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (config('barberflow.security.csp')) {
            $headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }

        // Só em HTTPS: o navegador passa a usar sempre HTTPS neste domínio por 1 ano.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Não conta que o servidor é PHP nem a versão. O header_remove() tira o que o próprio PHP acrescenta.
        $headers->remove('X-Powered-By');
        header_remove('X-Powered-By');

        return $response;
    }
}
