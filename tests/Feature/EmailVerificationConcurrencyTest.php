<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\EmailTakenException;
use App\Listeners\LinkClientToVerifiedUser;
use App\Models\Client;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * A confirmação do e-mail (App\Listeners\LinkClientToVerifiedUser) no mesmo
 * instante em que a barbearia cadastra um cliente com o mesmo e-mail
 * (ClientController::store), no MySQL (composer test:mysql). O SQLite não trava
 * linha, então no composer test estes testes são pulados; no SQLite local, as
 * transações IMMEDIATE já fazem uma gravação esperar a outra terminar.
 *
 * Mesmo roteiro do AppointmentConcurrencyTest: duas conexões com o mesmo banco,
 * "first" (a confirmação) e "second" (a barbearia), como dois pedidos ao mesmo
 * tempo, num processo só e em passos de ordem fixa. A espera por uma trava
 * aparece como o erro 1205 (Lock wait timeout), com o innodb_lock_wait_timeout
 * de 1 segundo na conexão que espera; fora dos testes, ela espera a outra
 * terminar. A conta é gravada de verdade, fora da transação do RefreshDatabase,
 * para as duas conexões enxergarem, e apagada no tearDown(), com os cadastros
 * de cliente do mesmo e-mail.
 */
class EmailVerificationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'conta.corrida@barberflow.test';

    private const PHONE = '67999990000';

    /**
     * Erro do MySQL quando a espera por uma trava passa do innodb_lock_wait_timeout.
     */
    private const LOCK_WAIT_TIMEOUT = 1205;

    /**
     * Erro do MySQL quando ele desfaz uma transação para sair de um deadlock.
     */
    private const DEADLOCK = 1213;

    /**
     * Quantas vezes o insert do cadastro recebeu o erro de deadlock
     * (deadlockOnTheClientInsert()).
     */
    private int $deadlocks = 0;

    private const REQUESTS = ['first', 'second'];

    private string $defaultConnection;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Só no MySQL (composer test:mysql): o SQLite não trava linha.');
        }

        $this->defaultConnection = DB::getDefaultConnection();

        foreach (self::REQUESTS as $name) {
            config(["database.connections.{$name}" => config("database.connections.{$this->defaultConnection}")]);
        }

        // A conta que acabou de confirmar o e-mail, ainda sem cadastro de cliente (ele nasce ou é ligado
        // na confirmação). O perfil vai por forceFill, como no cadastro de conta.
        $this->user = $this->on('second', function () {
            $user = new User(['name' => 'Conta Corrida', 'email' => self::EMAIL, 'password' => self::PASSWORD, 'phone' => self::PHONE]);
            $user->forceFill(['role' => UserRole::Client, 'email_verified_at' => now()])->save();

            return $user;
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
            $cleaner->table('clients')->where('email', self::EMAIL)->delete();
            $cleaner->table('users')->where('email', self::EMAIL)->delete();

            foreach (self::REQUESTS as $name) {
                DB::purge($name);
            }
        }

        parent::tearDown();
    }

    /**
     * A confirmação lê o e-mail com trava (lockForUpdate) e não acha cadastro.
     * Sem a linha, o MySQL trava o intervalo do índice unique onde o e-mail
     * entraria (gap lock), e o insert da barbearia com o mesmo e-mail espera a
     * confirmação terminar, em vez de entrar entre a leitura e o insert dela.
     * Quando ela termina, o índice unique recusa o insert da barbearia, que sai
     * como o 422 de e-mail em uso (EmailTakenException). A confirmação nunca
     * bate no índice, e a conta fica ligada ao cadastro que ela criou.
     */
    public function test_the_shop_waits_for_the_confirmation_and_gets_the_email_in_use(): void
    {
        $shopWaited = null;

        // A barbearia tenta gravar o mesmo e-mail entre a leitura e o insert da confirmação.
        DB::connection('first')->beforeExecuting(function (string $sql) use (&$shopWaited) {
            if ($shopWaited === null && str_starts_with($sql, 'insert into `clients`')) {
                $shopWaited = $this->waitsForALock('second', fn () => $this->shopSaves());
            }
        });

        $this->confirm();

        $this->assertTrue($shopWaited, 'O insert da barbearia devia esperar a trava da confirmação.');

        // A barbearia, liberada, recebe a recusa do índice unique, que vira o mesmo 422 da regra unique.
        try {
            $this->shopSaves();
            $this->fail('A barbearia devia receber o 422 de e-mail em uso.');
        } catch (EmailTakenException $e) {
            $this->assertSame(['email' => ['Este e-mail já está cadastrado.']], $e->errors());
        }

        $this->assertAccountLinkedToTheOnlyClient(name: 'Conta Corrida');
    }

    /**
     * O cadastro da barbearia chega primeiro e ainda não terminou: a leitura com
     * trava da confirmação espera por ele, em vez de não enxergar a linha nova e
     * bater no índice unique depois. Quando a barbearia termina, a confirmação
     * acha o cadastro que acabou de nascer e liga a conta a ele, com o telefone
     * da conta, porque o da barbearia está vazio.
     */
    public function test_the_confirmation_waits_for_the_shop_and_links_the_account_to_the_new_client(): void
    {
        // A transação da barbearia gravou e ainda não terminou.
        DB::connection('second')->beginTransaction();
        $this->shopSaves();

        // Cada espera vencida (1205) faz o listener rodar a transação de novo, até LinkClientToVerifiedUser::ATTEMPTS
        // vezes: com a barbearia parada aqui, ele desiste com o mesmo erro.
        $this->assertTrue($this->waitsForALock('first', fn () => $this->confirm()), 'A confirmação devia esperar a trava da barbearia.');

        DB::connection('second')->commit();

        $this->confirm();

        $this->assertAccountLinkedToTheOnlyClient(name: 'Cliente da barbearia');
    }

    /**
     * Duas contas confirmam o e-mail no mesmo instante, com e-mails que caem no
     * mesmo intervalo do índice unique: as duas leituras com trava travam o
     * intervalo, os dois inserts esperam um pelo outro, e o MySQL desfaz uma
     * das transações (deadlock, erro 1213). O deadlock de verdade precisa de
     * dois processos (num processo só, o insert que espera para o teste até o
     * innodb_lock_wait_timeout), então aqui o primeiro insert do cadastro
     * recebe o erro que o MySQL manda. O listener roda a transação de novo, e a
     * conta fica ligada ao cadastro que nasce na segunda vez.
     */
    public function test_the_confirmation_runs_again_after_a_deadlock(): void
    {
        $this->deadlockOnTheClientInsert(times: 1);

        $this->confirm();

        $this->assertSame(1, $this->deadlocks);
        $this->assertAccountLinkedToTheOnlyClient(name: 'Conta Corrida');
    }

    /**
     * Com o deadlock em todas as vezes, o listener desiste depois de
     * LinkClientToVerifiedUser::ATTEMPTS transações, com o erro do MySQL (o
     * pedido responde 500, como antes da repetição), e cada uma é desfeita.
     */
    public function test_the_confirmation_gives_up_after_the_attempts(): void
    {
        $this->deadlockOnTheClientInsert(times: PHP_INT_MAX);

        try {
            $this->confirm();
            $this->fail('A confirmação devia desistir com o erro de deadlock.');
        } catch (QueryException $e) {
            $this->assertSame(self::DEADLOCK, $e->errorInfo[1] ?? null);
        }

        $this->assertSame(LinkClientToVerifiedUser::ATTEMPTS, $this->deadlocks);
        $this->assertSame(0, DB::connection('first')->transactionLevel());
    }

    /**
     * O insert do cadastro de cliente pela conexão da confirmação ("first")
     * recebe o erro de deadlock do MySQL (1213) nas $times primeiras vezes,
     * antes de chegar ao banco. $this->deadlocks conta quantas vezes ele saiu.
     */
    private function deadlockOnTheClientInsert(int $times): void
    {
        DB::connection('first')->beforeExecuting(function (string $sql, array $bindings) use ($times) {
            if ($this->deadlocks >= $times || ! str_starts_with($sql, 'insert into `clients`')) {
                return;
            }

            $this->deadlocks++;

            $error = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            $error->errorInfo = ['40001', self::DEADLOCK, 'Deadlock found when trying to get lock; try restarting transaction'];

            throw new QueryException('first', $sql, $bindings, $error);
        });
    }

    /**
     * A gravação do ClientController::store, pela conexão da barbearia: o insert
     * numa transação, com a recusa do índice unique de e-mail trocada pelo 422.
     */
    private function shopSaves(): Client
    {
        return $this->on('second', fn () => EmailTakenException::guard(
            fn () => DB::transaction(fn () => Client::create(['name' => 'Cliente da barbearia', 'email' => self::EMAIL])),
        ));
    }

    /**
     * A confirmação do e-mail: o Laravel dispara o Verified, e o listener liga a
     * conta a um cadastro de cliente, pela conexão "first".
     */
    private function confirm(): void
    {
        $this->on('first', fn () => app(LinkClientToVerifiedUser::class)->handle(new Verified($this->user)));
    }

    /**
     * true quando $callback, pela conexão do pedido, fica esperando uma trava
     * de outra transação (o MySQL desiste em 1 segundo, com o erro 1205); false
     * quando ele roda sem esperar.
     */
    private function waitsForALock(string $request, Closure $callback): bool
    {
        $connection = DB::connection($request);
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $callback();

            return false;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== self::LOCK_WAIT_TIMEOUT) {
                throw $e;
            }

            return true;
        } finally {
            $connection->statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
    }

    /**
     * Um cadastro de cliente só com o e-mail da conta, ligado a ela.
     */
    private function assertAccountLinkedToTheOnlyClient(string $name): void
    {
        $clients = DB::connection('second')->table('clients')->where('email', self::EMAIL)->get();

        $this->assertCount(1, $clients);
        $this->assertSame($this->user->id, (int) $clients[0]->user_id);
        $this->assertSame($name, $clients[0]->name);
        $this->assertSame(self::PHONE, $clients[0]->phone);
    }

    /**
     * Roda o callback com a conexão do pedido como padrão: é ela que o listener,
     * a gravação da barbearia e os models usam.
     */
    private function on(string $request, Closure $callback): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($request);

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }
}
