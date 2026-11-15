<?php

namespace Tests\Feature;

use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /**
     * Observações com dado pessoal, que a mensagem do erro do insert repetiria.
     */
    private const NOTES = 'Maria Sigilosa, alérgica a amônia, telefone (11) 98888-7777';

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
        // Segunda-feira, antes da abertura: as 10:00 do mesmo dia estão livres.
        $this->travelTo(Carbon::parse('2026-11-16 08:00:00'));
        $this->actingAsAdmin();

        $client = Client::create(['name' => 'Maria Sigilosa', 'email' => self::EMAIL, 'phone' => '(11) 98888-7777']);
        $barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);

        // Alguém apaga o serviço direto no banco (a aplicação só faz exclusão lógica) entre as travas da agenda
        // e o insert do agendamento: a chave estrangeira recusa o insert, e a mensagem do erro traz o insert com
        // as observações, que podem ter dado pessoal. A aplicação não trata essa recusa, que é um erro do
        // servidor de verdade (o e-mail repetido de dois pedidos ao mesmo tempo, que este teste usava antes,
        // virou o 422 da regra unique, no EmailTakenException).
        $this->beforeQuery('/^insert into appointments /', fn () => DB::table('services')->where('id', $service->id)->delete());
        Log::spy();

        $this->postJson('/api/appointments', [
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'date' => '2026-11-16',
            'start_time' => '10:00',
            'notes' => self::NOTES,
        ])->assertServerError();

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === self::MESSAGE
            && array_keys($context) === ['connection', 'code', 'driver_code', 'file']
            && $context['connection'] === config('database.default')
            && $context['code'] === '23000'
            && str_starts_with($context['file'], 'app/Services/AppointmentScheduler.php:')
            && ! str_contains((string) json_encode($context), 'Sigilosa'));
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
