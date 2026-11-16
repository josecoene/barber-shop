<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * GET /api/health: confere se a aplicação alcança o banco e o cache.
 *
 * É para monitoramento, deploy e testes de carga. O /up do Laravel continua raso
 * (só diz que o PHP responde), porque é ele que o balanceador olha: se o /up
 * dependesse do banco, uma queda do banco faria o Auto Scaling trocar as
 * instâncias sem parar.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(fn () => $this->roundTripCache()),
        ];

        $healthy = ! in_array('falhou', $checks, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'falhou',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /**
     * Roda a conferência e devolve só "ok" ou "falhou". O motivo da falha vai
     * para o log da aplicação, nunca para a resposta.
     */
    private function check(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'falhou';
        }
    }

    /**
     * Grava e lê de volta uma chave curta, única por chamada para duas
     * conferências ao mesmo tempo não lerem o valor uma da outra.
     */
    private function roundTripCache(): void
    {
        $key = 'health-check:'.Str::random(16);

        Cache::put($key, 'ok', 10);

        if (Cache::pull($key) !== 'ok') {
            throw new RuntimeException('O cache não devolveu o valor gravado.');
        }
    }
}
