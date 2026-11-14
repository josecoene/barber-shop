<?php

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    public function test_returns_200_when_database_and_cache_respond(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok', 'cache' => 'ok'],
            ]);
    }

    public function test_returns_503_when_the_database_is_down(): void
    {
        Exceptions::fake();

        // Troca o banco da conexão em uso por um que não existe (no SQLite, um arquivo; no MySQL do
        // phpunit.mysql.xml, um banco) e descarta a conexão já aberta.
        $connection = config('database.default');
        $missing = config("database.connections.{$connection}.driver") === 'sqlite'
            ? storage_path('framework/testing/nao-existe/banco.sqlite')
            : 'banco_que_nao_existe_test';

        config(["database.connections.{$connection}.database" => $missing]);
        DB::purge($connection);

        $response = $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'falhou',
                'checks' => ['database' => 'falhou', 'cache' => 'ok'],
            ]);

        $this->assertStringNotContainsString(basename($missing), $response->getContent());
        Exceptions::assertReported(QueryException::class);
    }

    public function test_returns_503_when_the_cache_is_down(): void
    {
        Exceptions::fake();
        Cache::shouldReceive('put')->andThrow(new RuntimeException('cache fora do ar'));

        $response = $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'falhou',
                'checks' => ['database' => 'ok', 'cache' => 'falhou'],
            ]);

        $this->assertStringNotContainsString('cache fora do ar', $response->getContent());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_stays_out_of_the_api_rate_limit(): void
    {
        // Faz o mesmo que o throttleApi() do bootstrap/app.php: põe 'throttle:api' no grupo api.
        // O limite de 1 por minuto barraria a segunda chamada de qualquer rota que o recebesse.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(1)->by($request->ip()));
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('api', 'throttle:api');

        $throttle = ThrottleRequests::class.':api';
        $this->assertContains($throttle, $router->gatherRouteMiddleware($router->getRoutes()->getByName('api.services.index')));
        $this->assertNotContains($throttle, $router->gatherRouteMiddleware($router->getRoutes()->getByName('api.health')));

        foreach (range(1, 3) as $call) {
            $this->getJson('/api/health')
                ->assertOk()
                ->assertHeaderMissing('X-RateLimit-Limit');
        }
    }
}
