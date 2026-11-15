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
 * A trava do prêmio da fidelidade no MySQL (composer test:mysql). O SQLite não
 * trava linha, então no composer test estes testes são pulados.
 *
 * Mesmo roteiro do PaymentConcurrencyTest: duas conexões com o mesmo banco,
 * "first" e "second", como dois pedidos ao mesmo tempo, cada um na sua
 * transação, num processo só e em passos de ordem fixa. Com 3 visitas por
 * prêmio, o cliente tem 3 atendimentos pagos e um prêmio, e dois atendimentos
 * concluídos sem pagamento: cada pedido tenta pagar um deles com o mesmo
 * prêmio. Os registros são gravados de verdade, fora da transação do
 * RefreshDatabase, para as duas conexões enxergarem, e apagados no tearDown().
 */
class LoyaltyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const LAST_MONDAY = '2026-11-09';

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

    /**
     * @var list<Appointment>
     */
    private array $appointments = [];

    private Appointment $paidByFirst;

    private Appointment $paidBySecond;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Só no MySQL (composer test:mysql): o SQLite não trava linha.');
        }

        $this->travelTo(Carbon::parse(self::MONDAY.' 12:00:00'));
        config(['barberflow.loyalty.visits' => 3]);

        $this->recorder = app(PaymentRecorder::class);
        $this->defaultConnection = DB::getDefaultConnection();

        foreach (self::REQUESTS as $name) {
            config(["database.connections.{$name}" => config("database.connections.{$this->defaultConnection}")]);
        }

        // A conexão "second" ainda não abriu transação: os registros ficam gravados para as duas.
        $this->on('second', function () {
            $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro.fidelidade@barberflow.test']);
            $this->client = Client::create(['name' => 'Ana', 'email' => 'ana.fidelidade@barberflow.test']);
            $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);

            // Três visitas pagas: o cliente ganhou um prêmio.
            foreach (['09:00:00', '09:30:00', '10:00:00'] as $time) {
                Payment::create([
                    'appointment_id' => $this->completed(self::LAST_MONDAY, $time)->id,
                    'amount' => 35,
                    'method' => 'pix',
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
            }

            $this->paidByFirst = $this->completed(self::MONDAY, '09:00:00');
            $this->paidBySecond = $this->completed(self::MONDAY, '10:00:00');
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
            $ids = array_map(fn (Appointment $appointment) => $appointment->id, $this->appointments);

            if ($ids !== []) {
                $cleaner->table('payments')->whereIn('appointment_id', $ids)->delete();
                $cleaner->table('appointments')->whereIn('id', $ids)->delete();
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

    public function test_recording_with_the_reward_keeps_the_client_locked_until_it_ends(): void
    {
        DB::connection('first')->beginTransaction();
        $this->recordWithReward('first', $this->paidByFirst);

        // Outro pagamento do mesmo cliente espera a vez, mesmo sendo de outro atendimento.
        $this->assertFalse($this->secondCanLock('clients', $this->client->id));

        DB::connection('first')->commit();

        $this->assertTrue($this->secondCanLock('clients', $this->client->id));
    }

    public function test_the_second_payment_sees_the_reward_used_while_it_waited(): void
    {
        // O primeiro pedido paga um atendimento com o prêmio e ainda não terminou.
        DB::connection('first')->beginTransaction();
        $this->recordWithReward('first', $this->paidByFirst);

        DB::connection('second')->beginTransaction();

        // O primeiro termina logo antes de o segundo travar o cliente, como quando o segundo espera na
        // trava. A conta dos prêmios do segundo vem depois das travas e enxerga o prêmio que o primeiro
        // usou (a foto do MySQL nasce na primeira leitura sem trava), e o segundo nem tenta o insert. Se
        // alguma leitura sem trava viesse antes da trava do cliente, a foto seria de antes do pagamento do
        // primeiro, e o mesmo prêmio pagaria os dois atendimentos.
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
            $this->recordWithReward('second', $this->paidBySecond);
            $this->fail('O segundo pedido devia enxergar o prêmio que o primeiro usou.');
        } catch (ValidationException $e) {
            $this->assertSame([
                'use_loyalty_reward' => ['O cliente ainda não tem prêmio de fidelidade disponível: 0 de 3 visitas, sem contar este atendimento.'],
            ], $e->errors());
        }

        $this->assertTrue($committed);
        $this->assertFalse($secondTriedToInsert);

        $rewards = DB::connection('first')->table('payments')
            ->whereIn('appointment_id', [$this->paidByFirst->id, $this->paidBySecond->id])
            ->where('loyalty_reward', true)
            ->pluck('appointment_id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();

        $this->assertSame([$this->paidByFirst->id], $rewards);
    }

    /**
     * Atendimento concluído do cliente com o Pedro, gravado pela conexão atual.
     */
    private function completed(string $date, string $time): Appointment
    {
        $start = Carbon::parse("{$date} {$time}");

        return $this->appointments[] = Appointment::create([
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => $date,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'status' => 'completed',
            'price' => 35,
        ]);
    }

    /**
     * Registra o pagamento com o prêmio, em dinheiro, pela conexão do pedido.
     */
    private function recordWithReward(string $request, Appointment $appointment): Payment
    {
        return $this->on($request, fn () => $this->recorder->record($appointment, ['method' => 'cash', 'use_loyalty_reward' => true]));
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
