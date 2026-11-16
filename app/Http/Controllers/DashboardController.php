<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/dashboard?from=Y-m-d&to=Y-m-d
 *
 * Números da barbearia no período (faturamento, atendimentos por status, ticket
 * médio, taxa de faltas, ocupação, top 5 serviços e resumo por barbeiro), só
 * para o admin (Gate view-dashboard). As definições de cada número ficam no
 * App\Services\DashboardMetrics.
 *
 * Sem from e to, vale o mês atual, do primeiro ao último dia, no fuso do app
 * (APP_TIMEZONE). Com um, o outro também é obrigatório. O período tem no
 * máximo 366 dias, com os dois dias incluídos.
 */
class DashboardController extends Controller
{
    /**
     * Um ano, contando o 29 de fevereiro.
     */
    private const MAX_DAYS = 366;

    public function __construct(private readonly DashboardMetrics $metrics) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('view-dashboard');

        $validated = $request->validate([
            'from' => 'nullable|required_with:to|date_format:Y-m-d',
            'to' => 'nullable|required_with:from|date_format:Y-m-d|after_or_equal:from',
        ]);

        // O mês do "hoje" no fuso do app; as contas de dias seguem em UTC, sem horário de verão no meio.
        $month = CarbonImmutable::createFromFormat('!Y-m-d', now()->toDateString(), 'UTC');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'] ?? $month->startOfMonth()->toDateString(), 'UTC');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'] ?? $month->endOfMonth()->toDateString(), 'UTC');

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'to' => 'O período pode ter no máximo '.self::MAX_DAYS.' dias.',
            ]);
        }

        // As taxas saem sempre como número decimal (25.0, e não 25), para o tipo não mudar com o valor.
        return response()->json(
            $this->metrics->forPeriod($from->toDateString(), $to->toDateString()),
            options: JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
