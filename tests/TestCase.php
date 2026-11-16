<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PDO;
use PDOStatement;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Tests\Support\LostConnectionStatement;

abstract class TestCase extends BaseTestCase
{
    /**
     * Senha de todos os usuários criados pelos helpers abaixo.
     */
    protected const PASSWORD = 'senha-de-teste-123';

    /**
     * Regras da agenda, da fidelidade e dos lembretes que os testes supõem: os
     * padrões do config/barberflow.php. Ficam fixas aqui para nem o .env local
     * nem uma variável de ambiente (do container ou do CI) mudarem o resultado
     * da suíte. O teste que precisa de outro valor muda com config([...]).
     */
    private const BARBERFLOW_CONFIG = [
        'barberflow.business' => [
            'opening' => '09:00',
            'closing' => '19:00',
            'slot_minutes' => 30,
            'days' => [1, 2, 3, 4, 5, 6],
        ],
        'barberflow.booking_days_ahead' => 60,
        'barberflow.client_cancel_min_hours' => 2,
        'barberflow.client_max_active_appointments' => 3,
        'barberflow.loyalty' => [
            'visits' => 10,
            'discount_percent' => 100,
        ],
        'barberflow.reminders' => [
            'hours_before' => 24,
            'resend_minutes' => 10,
        ],
        'barberflow.barbershop' => [
            'name' => 'BarberFlow',
            'address' => '',
            'phone' => '',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(self::BARBERFLOW_CONFIG);
    }

    /**
     * O RefreshDatabase apaga e recria o banco a cada execução. Antes dele, confere
     * que o banco é de teste: SQLite em memória (phpunit.xml) ou um banco com "test"
     * no nome (phpunit.mysql.xml). Assim a configuração em cache (php artisan
     * optimize), que faz os testes ignorarem o phpunit.xml, ou um DB_DATABASE de
     * outro ambiente no ambiente do processo não apagam um banco de verdade.
     */
    protected function setUpTraits()
    {
        // Lê da configuração, sem abrir conexão. O DB_URL, quando existe, vale mais que o
        // database da conexão, e o ConfigurationUrlParser faz a mesma conta que o Laravel.
        $connection = (string) config('database.default');
        $config = (new ConfigurationUrlParser)->parseConfiguration((array) config("database.connections.{$connection}"));
        $database = (string) ($config['database'] ?? '');

        if ($database !== ':memory:' && ! str_contains(Str::lower(basename($database)), 'test')) {
            throw new RuntimeException(
                "Os testes apagam o banco [{$database}], que não parece ser de teste. Use o SQLite em memória do "
                .'phpunit.xml ou um banco com "test" no nome, e rode php artisan config:clear se a configuração estiver em cache.'
            );
        }

        return parent::setUpTraits();
    }

    /**
     * Admin com o e-mail confirmado.
     */
    protected function makeAdmin(): User
    {
        return $this->makeUser(UserRole::Admin, 'Admin Teste', 'admin.'.Str::lower(Str::random(8)).'@barberflow.test');
    }

    /**
     * Login de barbeiro ligado ao cadastro informado ou a um cadastro novo, com o
     * mesmo nome e e-mail, como o BarberController faz.
     */
    protected function makeBarberUser(?Barber $barber = null): User
    {
        $barber ??= Barber::create([
            'name' => 'Barbeiro Teste',
            'email' => 'barbeiro.'.Str::lower(Str::random(8)).'@barberflow.test',
        ]);

        $user = $this->makeUser(UserRole::Barber, $barber->name, $barber->email);

        $barber->user()->associate($user)->save();

        return $user;
    }

    /**
     * Conta de cliente ligada ao cadastro informado ou a um cadastro novo. Com
     * $verified false, a conta fica com o e-mail ainda não confirmado.
     */
    protected function makeClientUser(?Client $client = null, bool $verified = true): User
    {
        $client ??= Client::create([
            'name' => 'Cliente Teste',
            'email' => 'cliente.'.Str::lower(Str::random(8)).'@barberflow.test',
        ]);

        $user = $this->makeUser(UserRole::Client, $client->name, $client->email, $verified);

        $client->user()->associate($user)->save();

        return $user;
    }

    /**
     * Autentica a API como admin (Sanctum::actingAs, sem token de verdade).
     */
    protected function actingAsAdmin(): User
    {
        return Sanctum::actingAs($this->makeAdmin());
    }

    protected function actingAsBarber(?Barber $barber = null): User
    {
        return Sanctum::actingAs($this->makeBarberUser($barber));
    }

    protected function actingAsClient(?Client $client = null, bool $verified = true): User
    {
        return Sanctum::actingAs($this->makeClientUser($client, $verified));
    }

    /**
     * Troca o envio de e-mail por um que sempre falha, como um servidor de e-mail
     * fora do ar: a mensagem é montada de verdade (o template renderiza) e o
     * envio lança TransportException com o endereço no texto do erro, como os
     * servidores de verdade costumam fazer. Desfaz um Mail::fake() anterior; para
     * voltar a contar os envios, chame Mail::fake() de novo.
     */
    protected function failMailDelivery(): void
    {
        Mail::swap(new MailManager($this->app));

        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                $recipients = array_map(fn (Address $address) => $address->getAddress(), $message->getEnvelope()->getRecipients());

                throw new TransportException('Conexão recusada ao enviar para '.implode(', ', $recipients).'.');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });

        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    }

    /**
     * Roda $callback uma vez, logo antes da consulta número $occurrence (a
     * primeira, por padrão) da conexão padrão cujo SQL casa com $pattern
     * (expressão regular sobre o SQL sem aspas nem crases, para valer igual no
     * SQLite e no MySQL). Simula outro pedido que chega no meio de um fluxo,
     * entre a leitura e a escrita, sem precisar de duas conexões. As consultas
     * do próprio $callback não contam nem disparam de novo.
     */
    protected function beforeQuery(string $pattern, Closure $callback, int $occurrence = 1): void
    {
        $seen = 0;

        DB::connection()->beforeExecuting(function (string $sql) use (&$seen, $pattern, $callback, $occurrence) {
            if ($seen >= $occurrence || preg_match($pattern, str_replace(['"', '`'], '', $sql)) !== 1) {
                return;
            }

            if (++$seen === $occurrence) {
                $callback();
            }
        });
    }

    /**
     * Como o beforeQuery(), mas logo depois da consulta, com o resultado dela já
     * lido. Serve para o outro pedido que grava entre a validação (a regra unique
     * já contou zero) e uma gravação feita dentro de um DB::transaction(): gravado
     * antes da transação, o registro dele continua no banco quando ela é desfeita,
     * como num banco de verdade. Gravado no beforeQuery() do próprio insert, ele
     * entraria na transação deste pedido e sumiria junto.
     */
    protected function afterQuery(string $pattern, Closure $callback, int $occurrence = 1): void
    {
        $seen = 0;
        $connection = DB::connection();

        $connection->listen(function (QueryExecuted $query) use (&$seen, $pattern, $callback, $occurrence, $connection) {
            if ($query->connection !== $connection || $seen >= $occurrence || preg_match($pattern, str_replace(['"', '`'], '', $query->sql)) !== 1) {
                return;
            }

            if (++$seen === $occurrence) {
                $callback();
            }
        });
    }

    /**
     * Simula a leitura que desiste no DB_READ_TIMEOUT depois que o banco já
     * gravou: o primeiro comando da conexão padrão cujo SQL casa com $pattern
     * (como no beforeQuery()) roda de verdade e, logo depois, dá o erro de conexão
     * perdida do MySQL (2006). Fora de uma transação, o Laravel reconecta e repete
     * o comando; a reconexão daqui volta à mesma conexão, como a de verdade volta
     * ao mesmo banco (no SQLite em memória, uma conexão nova seria um banco vazio).
     *
     * Dentro de uma transação o Laravel nunca repete, e o RefreshDatabase roda
     * cada teste dentro de uma: este helper fecha a dele, gravando o que o teste
     * já criou. O RefreshDatabase percebe a transação fechada e recria as tabelas
     * antes do próximo teste.
     */
    protected function loseConnectionAfter(string $pattern): void
    {
        $connection = DB::connection();

        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }

        $pdo = $connection->getPdo();

        LostConnectionStatement::$pattern = $pattern;
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LostConnectionStatement::class]);
        $connection->setReconnector(fn (Connection $lost) => $lost->setPdo($pdo));

        // No SQLite em memória, o RefreshDatabase usa o mesmo PDO no teste seguinte.
        $this->beforeApplicationDestroyed(function () use ($pdo) {
            LostConnectionStatement::$pattern = null;
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        });
    }

    /**
     * O perfil vai por forceFill, como no código da aplicação: role não é fillable.
     */
    private function makeUser(UserRole $role, string $name, string $email, bool $verified = true): User
    {
        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
        ]);

        $user->forceFill([
            'role' => $role,
            'email_verified_at' => $verified ? now() : null,
        ])->save();

        return $user;
    }
}
