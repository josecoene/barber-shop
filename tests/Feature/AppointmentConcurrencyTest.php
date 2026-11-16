<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Services\AppointmentScheduler;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * As travas da agenda no MySQL (composer test:mysql). O SQLite não trava linha,
 * então no composer test estes testes são pulados.
 *
 * Cada teste abre duas conexões com o mesmo banco, "first" e "second", como
 * dois pedidos ao mesmo tempo, cada um na sua transação, e roda o
 * AppointmentScheduler numa e na outra trocando a conexão padrão. Tudo num
 * processo só: os passos seguem uma ordem fixa e o resultado não depende de
 * sorte. A prova com pedidos simultâneos de verdade (vários processos) fica
 * para o teste de carga concorrencia.js.
 *
 * Os cadastros são gravados de verdade, fora da transação do RefreshDatabase,
 * para as duas conexões enxergarem, e apagados no tearDown().
 */
class AppointmentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    /**
     * Erro do MySQL quando o FOR UPDATE NOWAIT encontra a linha travada por outra transação.
     */
    private const LOCK_NOWAIT = 3572;

    private const REQUESTS = ['first', 'second'];

    private AppointmentScheduler $scheduler;

    private string $defaultConnection;

    private Barber $pedro;

    private Barber $joao;

    private Client $ana;

    private Client $bruno;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Só no MySQL (composer test:mysql): o SQLite não trava linha.');
        }

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));
        $this->scheduler = app(AppointmentScheduler::class);
        $this->defaultConnection = DB::getDefaultConnection();

        foreach (self::REQUESTS as $name) {
            config(["database.connections.{$name}" => config("database.connections.{$this->defaultConnection}")]);
        }

        // A conexão "second" ainda não abriu transação: os cadastros ficam gravados para as duas.
        $this->on('second', function () {
            $this->pedro = Barber::create(['name' => 'Pedro', 'email' => 'pedro.concorrencia@barberflow.test']);
            $this->joao = Barber::create(['name' => 'Joao', 'email' => 'joao.concorrencia@barberflow.test']);
            $this->ana = Client::create(['name' => 'Ana', 'email' => 'ana.concorrencia@barberflow.test']);
            $this->bruno = Client::create(['name' => 'Bruno', 'email' => 'bruno.concorrencia@barberflow.test']);
            $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
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

            $barbers = array_map(fn (Barber $barber) => $barber->id, array_filter([$this->pedro ?? null, $this->joao ?? null]));
            $clients = array_map(fn (Client $client) => $client->id, array_filter([$this->ana ?? null, $this->bruno ?? null]));
            $cleaner = DB::connection('second');

            $cleaner->table('appointments')->whereIn('barber_id', $barbers)->delete();
            $cleaner->table('clients')->whereIn('id', $clients)->delete();
            $cleaner->table('barbers')->whereIn('id', $barbers)->delete();

            if (isset($this->service)) {
                $cleaner->table('services')->where('id', $this->service->id)->delete();
            }

            foreach (self::REQUESTS as $name) {
                DB::purge($name);
            }
        }

        parent::tearDown();
    }

    public function test_bookings_with_another_barber_and_another_client_do_not_wait_for_each_other(): void
    {
        // O primeiro pedido agenda Pedro com Ana às 10:00 e ainda não terminou: as travas continuam com ele.
        DB::connection('first')->beginTransaction();
        $this->schedule('first', $this->pedro, $this->ana);

        // O segundo, no mesmo horário, com outro barbeiro e outro cliente, não espera o primeiro. Se a
        // consulta de sobreposição travasse o intervalo do índice (FOR UPDATE), o segundo esperaria o
        // primeiro e, com os dois pedidos assim, o MySQL derrubaria um deles por deadlock.
        DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::connection('second')->beginTransaction();

        $appointment = $this->schedule('second', $this->joao, $this->bruno);

        $this->assertSame($this->joao->id, $appointment->barber_id);
        $this->assertSame('10:00:00', $appointment->start_time);
    }

    public function test_a_booking_keeps_the_barber_the_client_and_the_service_locked_until_it_ends(): void
    {
        DB::connection('first')->beginTransaction();
        $this->schedule('first', $this->pedro, $this->ana);

        // Outro pedido não trava o barbeiro nem o cliente do primeiro: espera a vez.
        $this->assertFalse($this->secondCanLock('barbers', $this->pedro->id));
        $this->assertFalse($this->secondCanLock('clients', $this->ana->id));

        // O serviço fica com trava compartilhada: outro agendamento usa, mas a exclusão dele espera.
        $this->assertFalse($this->secondCanLock('services', $this->service->id));

        // Outro barbeiro e outro cliente ficam livres.
        $this->assertTrue($this->secondCanLock('barbers', $this->joao->id));
        $this->assertTrue($this->secondCanLock('clients', $this->bruno->id));

        DB::connection('first')->commit();

        $this->assertTrue($this->secondCanLock('barbers', $this->pedro->id));
        $this->assertTrue($this->secondCanLock('clients', $this->ana->id));
    }

    public function test_the_second_booking_sees_the_one_saved_while_it_waited(): void
    {
        // O primeiro pedido agenda Ana com o Pedro às 10:00 e ainda não terminou.
        DB::connection('first')->beginTransaction();
        $this->schedule('first', $this->pedro, $this->ana);

        // O segundo começa antes de o primeiro terminar: Ana com o Joao, no mesmo horário.
        DB::connection('second')->beginTransaction();

        // O primeiro termina logo depois que o segundo trava o barbeiro e antes de ele travar a Ana, como
        // quando o segundo espera na trava do cliente. A consulta de sobreposição do segundo vem depois
        // das travas e enxerga o que o primeiro gravou (a foto do MySQL nasce na primeira leitura sem trava).
        $committed = false;

        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$committed) {
            if (! $committed && $query->connectionName === 'second' && str_contains($query->sql, 'from `barbers`')) {
                $committed = true;
                DB::connection('first')->commit();
            }
        });

        try {
            $this->schedule('second', $this->joao, $this->ana);
            $this->fail('O segundo pedido devia enxergar o agendamento que o primeiro gravou.');
        } catch (ValidationException $e) {
            $this->assertSame(['client_id' => ['O cliente já tem um horário marcado nesse período.']], $e->errors());
        }

        $this->assertTrue($committed);
    }

    /**
     * Agenda o serviço de 30 minutos na segunda às 10:00, pela conexão do pedido.
     */
    private function schedule(string $request, Barber $barber, Client $client): Appointment
    {
        return $this->on($request, fn () => $this->scheduler->schedule([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'service_id' => $this->service->id,
            'date' => self::MONDAY,
            'start_time' => '10:00',
        ]));
    }

    /**
     * Roda o callback com a conexão do pedido como padrão: é ela que o
     * AppointmentScheduler e os models usam.
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
     * true quando a conexão "second" consegue travar a linha agora, sem esperar.
     */
    private function secondCanLock(string $table, int $id): bool
    {
        $connection = DB::connection('second');
        $connection->beginTransaction();

        try {
            $connection->select("select id from `{$table}` where id = ? for update nowait", [$id]);

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
