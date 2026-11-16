<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Mail;
use PDO;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * Tempo máximo para abrir a conexão com o MySQL, com o Redis e com o servidor de
 * e-mail e para esperar a resposta de cada um (config/database.php e
 * config/mail.php). Sem ele, com o banco, o cache ou o servidor de e-mail fora do
 * ar ou congelados, cada requisição esperaria o padrão do PHP (30 segundos para
 * abrir a conexão com o MySQL, 24 horas por resposta dele e 60 segundos no Redis e
 * no SMTP) antes do erro. O arquivo de configuração é lido de novo com as
 * variáveis de cada teste, sem depender do .env de quem roda.
 *
 * A espera pela resposta do MySQL é o mysqlnd.net_read_timeout do PHP, que o
 * AppServiceProvider aplica só nas requisições web. Os testes dele sobem o app de
 * novo, como uma requisição web (APP_RUNNING_IN_CONSOLE=false, que o Laravel lê
 * antes de olhar se o PHP roda na linha de comando) e como a linha de comando.
 */
class ConnectionTimeoutTest extends TestCase
{
    private const VARIABLES = ['DB_CONNECT_TIMEOUT', 'DB_READ_TIMEOUT', 'REDIS_CONNECT_TIMEOUT', 'REDIS_READ_TIMEOUT'];

    private const READ_TIMEOUT_INI = 'mysqlnd.net_read_timeout';

    protected function tearDown(): void
    {
        // O ini_set vale para o processo inteiro do PHPUnit: volta ao valor do php.ini.
        ini_restore(self::READ_TIMEOUT_INI);

        parent::tearDown();
    }

    public function test_connections_give_up_after_3_seconds_by_default(): void
    {
        $this->assertTimeouts($this->databaseConfig([]), mysql: 3, mysqlRead: 5, redisConnect: 3.0, redisRead: 3.0);

        // Vazia no .env vale o padrão, como as outras variáveis do projeto.
        $this->assertTimeouts($this->databaseConfig(array_fill_keys(self::VARIABLES, '')), mysql: 3, mysqlRead: 5, redisConnect: 3.0, redisRead: 3.0);
    }

    public function test_the_timeouts_come_from_the_environment(): void
    {
        $config = $this->databaseConfig(['DB_CONNECT_TIMEOUT' => '5', 'DB_READ_TIMEOUT' => '8', 'REDIS_CONNECT_TIMEOUT' => '1.5', 'REDIS_READ_TIMEOUT' => '2']);

        $this->assertTimeouts($config, mysql: 5, mysqlRead: 8, redisConnect: 1.5, redisRead: 2.0);
    }

    /**
     * Com o servidor de e-mail de pé e sem responder (congelado), o envio
     * desiste no limite, e o pedido não fica preso até o 504 do Nginx (60 s).
     */
    public function test_smtp_gives_up_after_10_seconds_by_default(): void
    {
        $this->assertSame(10.0, $this->smtpConfig(null)['timeout']);

        // Vazia ou 0 no .env vale o padrão, como as outras variáveis do projeto.
        $this->assertSame(10.0, $this->smtpConfig('')['timeout']);
        $this->assertSame(10.0, $this->smtpConfig('0')['timeout']);

        // O Laravel entrega o limite ao SMTP do Symfony Mailer, que usa o mesmo valor para abrir a conexão e
        // para esperar cada resposta. Sem ele, o Symfony esperaria o default_socket_timeout do PHP (60 s).
        $transport = app('mail.manager')->createSymfonyTransport($this->smtpConfig(null));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertInstanceOf(SocketStream::class, $transport->getStream());
        $this->assertSame(10.0, $transport->getStream()->getTimeout());
    }

    public function test_the_smtp_timeout_comes_from_the_environment(): void
    {
        $config = $this->smtpConfig('4.5');

        $this->assertSame(4.5, $config['timeout']);
        $this->assertSame(4.5, app('mail.manager')->createSymfonyTransport($config)->getStream()->getTimeout());

        // Com o app subindo de novo, como num pedido, o valor do ambiente vence o MAIL_TIMEOUT do .env (o .env
        // do CI vem do .env.example, com 10) e chega ao mailer smtp do app.
        $this->bootWith(['MAIL_TIMEOUT' => '7']);

        $this->assertSame(7.0, config('mail.mailers.smtp.timeout'));
        $this->assertSame(7.0, Mail::mailer('smtp')->getSymfonyTransport()->getStream()->getTimeout());
    }

    /**
     * Com o MySQL de pé e sem responder, a leitura desiste no limite, e o pedido
     * não fica preso até o 504 do Nginx.
     */
    public function test_web_requests_wait_for_each_database_read_at_most_the_limit(): void
    {
        $this->skipWithoutMysqlnd();

        $this->bootWith(['APP_RUNNING_IN_CONSOLE' => 'false', 'DB_READ_TIMEOUT' => '7']);

        $this->assertFalse($this->app->runningInConsole());
        $this->assertSame('7', ini_get(self::READ_TIMEOUT_INI));
    }

    /**
     * As migrations, o agendador (lembretes) e os seeders rodam na linha de
     * comando e não podem cair no meio de uma consulta longa: ficam com o valor do
     * php.ini, mesmo com o DB_READ_TIMEOUT definido.
     */
    public function test_the_command_line_keeps_the_read_limit_of_the_php_ini(): void
    {
        $this->skipWithoutMysqlnd();

        $this->bootWith(['APP_RUNNING_IN_CONSOLE' => null, 'DB_READ_TIMEOUT' => '7']);

        $this->assertTrue($this->app->runningInConsole());
        $this->assertSame(ini_get_all('mysqlnd')[self::READ_TIMEOUT_INI]['global_value'], ini_get(self::READ_TIMEOUT_INI));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertTimeouts(array $config, int $mysql, int $mysqlRead, float $redisConnect, float $redisRead): void
    {
        $this->assertSame($mysql, $config['connections']['mysql']['options'][PDO::ATTR_TIMEOUT]);
        $this->assertSame($mysqlRead, $config['connections']['mysql']['read_timeout']);

        // As duas conexões do Redis: a do cache e a da sessão (default).
        foreach (['default', 'cache'] as $connection) {
            $this->assertSame($redisConnect, $config['redis'][$connection]['timeout']);
            $this->assertSame($redisRead, $config['redis'][$connection]['read_timeout']);
        }
    }

    /**
     * O config/database.php lido com estas variáveis no ambiente (as que não
     * vierem ficam fora dele).
     *
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function databaseConfig(array $values): array
    {
        $variables = array_merge(array_fill_keys(self::VARIABLES, null), $values);

        return $this->withVariables($variables, fn () => require base_path('config/database.php'));
    }

    /**
     * O mailer smtp do config/mail.php lido com este MAIL_TIMEOUT no ambiente
     * (null tira a variável).
     *
     * @return array<string, mixed>
     */
    private function smtpConfig(?string $timeout): array
    {
        return $this->withVariables(['MAIL_TIMEOUT' => $timeout], fn () => require base_path('config/mail.php'))['mailers']['smtp'];
    }

    /**
     * Sobe o app de novo, com o boot dos service providers, com estas variáveis
     * no ambiente (null tira a variável).
     *
     * @param  array<string, string|null>  $values
     */
    private function bootWith(array $values): void
    {
        // O Laravel guarda num repositório estático as variáveis que ele mesmo leu do .env e, quando o app
        // sobe de novo, deixa o .env escrever por cima delas. Com um .env copiado do .env.example (o do CI),
        // o DB_READ_TIMEOUT=5 dele venceria o valor do teste. Com um repositório novo, as variáveis do teste
        // contam como vindas de fora, e o .env não as troca.
        Env::enablePutenv();

        $this->withVariables($values, fn () => $this->refreshApplication());
    }

    /**
     * Roda $callback com as variáveis no ambiente e devolve o ambiente como
     * estava depois.
     *
     * @param  array<string, string|null>  $values
     */
    private function withVariables(array $values, Closure $callback): mixed
    {
        $saved = [];

        foreach ($values as $name => $value) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $this->setVariable($name, $value);
        }

        try {
            return $callback();
        } finally {
            foreach ($saved as $name => [$server, $env, $putenv]) {
                $this->setVariable($name, null);

                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }

                if ($env !== null) {
                    $_ENV[$name] = $env;
                }

                if ($putenv !== false) {
                    putenv("{$name}={$putenv}");
                }
            }
        }
    }

    private function setVariable(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }

    /**
     * O mysqlnd vem compilado nas imagens oficiais do PHP (a do container local,
     * a de produção e a do CI). Sem ele, o PHP não tem essa diretiva.
     */
    private function skipWithoutMysqlnd(): void
    {
        if (! extension_loaded('mysqlnd')) {
            $this->markTestSkipped('Sem o mysqlnd, o PHP não tem o mysqlnd.net_read_timeout.');
        }
    }
}
