<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->name('api.')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsJson = fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($wantsJson);

        // 404 da API em pt-BR, sem o nome do model (App\Models\X) que o Laravel põe na mensagem.
        // O Laravel transforma o ModelNotFoundException do route model binding em
        // NotFoundHttpException e guarda o original no getPrevious().
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $message = $e->getPrevious() instanceof ModelNotFoundException
                ? 'Registro não encontrado.'
                : 'Rota não encontrada.';

            return response()->json(['message' => $message], 404, $e->getHeaders());
        });

        // 405 mantém o cabeçalho Allow com os métodos aceitos pela rota.
        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json(['message' => 'Método não permitido para esta rota.'], 405, $e->getHeaders());
        });
    })->create();
