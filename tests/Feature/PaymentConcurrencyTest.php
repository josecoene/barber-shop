<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Service;
use App\Services\PaymentRecorder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A trava do registro de pagamento no MySQL (composer test:mysql). O SQLite não
 * trava linha, então no composer test estes testes são pulados.
 *
 * Mesmo roteiro do AppointmentConcurrencyTest: duas conexões com o mesmo banco,
 * "first" e "second", como dois pedidos ao mesmo tempo, cada um na sua
 * transação, num processo só e em passos de ordem fixa. Os cadastros e o
 * atendimento são gravados de verdade, fora da transação do RefreshDatabase,
 * para as duas conexões enxergarem, e apagados no tearDown().
 */
class PaymentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    /**
     * Erro do MySQL quando o FOR UPDATE NOWAIT encontra a linha travada por outra transação.
     */
    private const LOCK_NOWAIT = 3572;

    private const REQUESTS = ['first', 'second'];

    private PaymentRecorder $recorder;

    private string $defaultConnection;

    private Barber $barber;

    private Client $client;

    private Service $service;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Só no MySQL (composer test:mysql): o SQLite não trava linha.');
        }

        $this->travelTo(Carbon::parse(self::MONDAY.' 12:00:00'));
        $this->recorder = app(PaymentRecorder::class);
        $this->defaultConnection = DB::getDefaultConnection();

        foreach (self::REQUESTS as $name) {
            config(["database.connections.{$name}" => config("database.connections.{$this->defaultConnection}")]);
        }

        // A conexão "second" ainda não abriu transação: os registros ficam gravados para as duas.
        $this->on('second', function () {
            $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro.pagamento@barberflow.test']);
            $this->client = Client::create(['name' => 'Ana', 'email' => 'ana.pagamento@barberflow.test']);
            $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
            $this->appointment = Appointment::create([
                'client_id' => $this->client->id,
                'barber_id' => $this->barber->id,
                'service_id' => $this->service->id,
                'date' => self::MONDAY,
                'start_time' => '10:00:00',
                'end_time' => '10:30:00',
                'status' => 'completed',
                'price' => 35,
            ]);
        });
    }

    /**
     * Desfaz as transações que ficaram abertas e apaga o que os testes gravaram,
     * para não sobrar nada para os outros testes da suíte.
     */
    protected function tearDown(): void
    {
        if (isset($this->defaultConnection)) {
            DB::setDefaultConnection($this->defaultConnection);

            foreach (self::REQUESTS as $name) {
                while (DB::connection($name)->transactionLevel() > 0) {
                    DB::connection($name)->rollBack();
                }
            }

            $cleaner = DB::connection('second');

            if (isset($this->appointment)) {
                $cleaner->table('payments')->where('appointment_id', $this->appointment->id)->delete();
                $cleaner->table('appointments')->where('id', $this->appointment->id)->delete();
            }

            foreach (['clients' => $this->client ?? null, 'barbers' => $this->barber ?? null, 'services' => $this->service ?? null] as $table => $record) {
                if ($record !== null) {
                    $cleaner->table($table)->where('id', $record->id)->delete();
                }
            }

            foreach (self::REQUESTS as $name) {
                DB::purge($name);
            }
        }

        parent::tearDown();
    }

    public function test_recording_keeps_the_appointment_locked_until_it_ends(): void
    {
        DB::connection('first')->beginTransaction();
        $this->record('first');

        // Outro registro para o mesmo atendimento espera a vez.
        $this->assertFalse($this->secondCanLock($this->appointment->id));

        DB::connection('first')->commit();

        $this->assertTrue($this->secondCanLock($this->appointment->id));
    }

    public function test_the_second_recording_sees_the_payment_saved_while_it_waited(): void
    {
        // O primeiro pedido registra o pagamento e ainda não terminou.
        DB::connection('first')->beginTransaction();
        $this->record('first');

        DB::connection('second')->beginTransaction();

        // O primeiro termina logo antes de o segundo travar o atendimento, como quando o segundo
        // espera na trava. A conferência do segundo vem depois da trava e enxerga o pagamento do
        // primeiro (a foto do MySQL nasce na primeira leitura sem trava), e o segundo nem tenta o
        // insert. Se alguma leitura sem trava viesse antes da trava, a foto seria de antes do
        // pagamento do primeiro e só o unique do banco barraria o segundo, já no insert.
        $committed = false;
        $secondTriedToInsert = false;

        DB::connection('second')->beforeExecuting(function (string $sql) use (&$committed, &$secondTriedToInsert) {
            if (! $committed && str_contains($sql, 'for update')) {
                $committed = true;
                DB::connection('first')->commit();
            }

            if (str_starts_with($sql, 'insert into `payments`')) {
                $secondTriedToInsert = true;
            }
        });

        try {
            $this->record('second');
            $this->fail('O segundo pedido devia enxergar o pagamento que o primeiro gravou.');
        } catch (ValidationException $e) {
            $this->assertSame(['appointment_id' => ['Este atendimento já tem pagamento registrado.']], $e->errors());
        }

        $this->assertTrue($committed);
        $this->assertFalse($secondTriedToInsert);
        $this->assertSame(1, DB::connection('first')->table('payments')->where('appointment_id', $this->appointment->id)->count());
    }

    /**
     * Registra o pagamento no Pix, pela conexão do pedido.
     */
    private function record(string $request): Payment
    {
        return $this->on($request, fn () => $this->recorder->record($this->appointment, ['method' => 'pix']));
    }

    /**
     * Roda o callback com a conexão do pedido como padrão: é ela que o
     * PaymentRecorder e os models usam.
     */
    private function on(string $request, Closure $callback): mixed
    {
        DB::setDefaultConnection($request);

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($this->defaultConnection);
        }
    }

    /**
     * true quando a conexão "second" consegue travar a linha do atendimento agora, sem esperar.
     */
    private function secondCanLock(int $id): bool
    {
        $connection = DB::connection('second');
        $connection->beginTransaction();

        try {
            $connection->select('select id from `appointments` where id = ? for update nowait', [$id]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== self::LOCK_NOWAIT) {
                throw $e;
            }

            return false;
        } finally {
            $connection->rollBack();
        }
    }
}
