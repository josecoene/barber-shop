<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\ViewException;
use RuntimeException;
use Tests\TestCase;

/**
 * Log de erro de banco sem dado pessoal (bootstrap/app.php). A mensagem da
 * QueryException traz o SQL com os valores da consulta, e o log padrão do
 * Laravel gravaria essa mensagem inteira; o projeto grava só a conexão, os
 * códigos do erro e o arquivo:linha de quem fez a consulta. O Log::spy() recebe
 * também o log padrão do Laravel, então o once() confere que ele não gravou
 * nada. O banco fora do ar fica no HealthApiTest.
 */
class DatabaseErrorLogTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Erro no banco de dados.';

    private const EMAIL = 'maria.sigilosa@barberflow.test';

    public function test_logs_the_query_error_without_the_sql_and_the_values(): void
    {
        [$exception, $line] = $this->duplicateEmailError();
        Log::spy();

        report($exception);

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === self::MESSAGE
            && $context === [
                'connection' => config('database.default'),
                'code' => '23000',
                'driver_code' => $exception->errorInfo[1],
                'file' => 'tests/Feature/DatabaseErrorLogTest.php:'.$line,
            ]);
        $this->assertIsInt($exception->errorInfo[1]);
    }

    public function test_a_failed_request_logs_the_error_without_the_sql_and_the_values(): void
    {
        $this->actingAsAdmin();

        // Outro pedido grava o mesmo e-mail entre a validação e o insert: o unique do banco recusa, e a
        // mensagem do erro traz o insert com o nome, o e-mail e o telefone. Hoje essa corrida termina num
        // erro do servidor, sem tratamento próprio (docs/ARQUITETURA.md, "Limitações e riscos conhecidos"):
        // o que este teste confere é o log, e não a resposta. Se a corrida um dia virar 422, como no
        // PaymentRecorder, o erro de banco daqui precisa vir de outro caminho.
        $this->beforeQuery('/^insert into clients /', fn () => DB::table('clients')->insert([
            'name' => 'Outra Pessoa',
            'email' => self::EMAIL,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        Log::spy();

        $this->postJson('/api/clients', ['name' => 'Maria Sigilosa', 'email' => self::EMAIL, 'phone' => '(11) 98888-7777'])
            ->assertServerError();

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === self::MESSAGE
            && array_keys($context) === ['connection', 'code', 'driver_code', 'file']
            && $context['connection'] === config('database.default')
            && $context['code'] === '23000'
            && str_starts_with($context['file'], 'app/Http/Controllers/ClientController.php:'));
    }

    public function test_a_query_error_inside_another_error_is_logged_the_same_way(): void
    {
        [$exception, $line] = $this->duplicateEmailError();
        Log::spy();

        // O Blade embrulha o erro de uma consulta feita dentro da view e repete a mensagem dele.
        report(new ViewException($exception->getMessage().' (View: clients.blade.php)', 0, 1, __FILE__, __LINE__, $exception));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === self::MESSAGE
            && $context['file'] === 'tests/Feature/DatabaseErrorLogTest.php:'.$line
            && ! str_contains((string) json_encode($context), 'maria.sigilosa'));
    }

    public function test_other_errors_keep_the_default_log(): void
    {
        Log::spy();

        report(new RuntimeException('Falha qualquer, sem banco.'));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Falha qualquer, sem banco.'
            && ($context['exception'] ?? null) instanceof RuntimeException);
    }

    /**
     * Um e-mail repetido na tabela clients: o unique recusa, e a mensagem da
     * QueryException traz o insert com o nome e o e-mail. Devolve o erro e a
     * linha deste arquivo que fez a consulta.
     *
     * @return array{0: QueryException, 1: int}
     */
    private function duplicateEmailError(): array
    {
        Client::create(['name' => 'Maria Sigilosa', 'email' => self::EMAIL]);

        try {
            $line = __LINE__ + 1;
            Client::create(['name' => 'Maria Sigilosa', 'email' => self::EMAIL]);
        } catch (QueryException $exception) {
            // É isso que o log padrão gravaria.
            $this->assertStringContainsString(self::EMAIL, $exception->getMessage());
            $this->assertStringContainsString('insert into', $exception->getMessage());

            return [$exception, $line];
        }

        $this->fail('O e-mail repetido deveria ter sido recusado pelo banco.');
    }
}
