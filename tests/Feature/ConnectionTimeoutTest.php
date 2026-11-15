<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

/**
 * Tempo máximo para abrir a conexão com o MySQL e com o Redis e para esperar a
 * resposta do Redis (config/database.php). Sem ele, com o banco ou o cache fora
 * do ar, cada requisição esperaria o padrão do PHP (30 segundos para abrir a
 * conexão com o MySQL e 60 no Redis) antes do erro. O arquivo de configuração é
 * lido de novo com as variáveis de cada teste, sem depender do .env de quem roda.
 */
class ConnectionTimeoutTest extends TestCase
{
    private const VARIABLES = ['DB_CONNECT_TIMEOUT', 'REDIS_CONNECT_TIMEOUT', 'REDIS_READ_TIMEOUT'];

    public function test_connections_give_up_after_3_seconds_by_default(): void
    {
        $this->assertTimeouts($this->databaseConfig([]), mysql: 3, redisConnect: 3.0, redisRead: 3.0);

        // Vazia no .env vale o padrão, como as outras variáveis do projeto.
        $this->assertTimeouts($this->databaseConfig(array_fill_keys(self::VARIABLES, '')), mysql: 3, redisConnect: 3.0, redisRead: 3.0);
    }

    public function test_the_timeouts_come_from_the_environment(): void
    {
        $config = $this->databaseConfig(['DB_CONNECT_TIMEOUT' => '5', 'REDIS_CONNECT_TIMEOUT' => '1.5', 'REDIS_READ_TIMEOUT' => '2']);

        $this->assertTimeouts($config, mysql: 5, redisConnect: 1.5, redisRead: 2.0);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertTimeouts(array $config, int $mysql, float $redisConnect, float $redisRead): void
    {
        $this->assertSame($mysql, $config['connections']['mysql']['options'][PDO::ATTR_TIMEOUT]);

        // As duas conexões do Redis: a do cache e a da sessão (default).
        foreach (['default', 'cache'] as $connection) {
            $this->assertSame($redisConnect, $config['redis'][$connection]['timeout']);
            $this->assertSame($redisRead, $config['redis'][$connection]['read_timeout']);
        }
    }

    /**
     * O config/database.php lido com estas variáveis no ambiente (as que não
     * vierem ficam fora dele). Devolve o ambiente como estava depois.
     *
     * @param  array<string, string>  $values
     * @return array<string, mixed>
     */
    private function databaseConfig(array $values): array
    {
        $saved = [];

        foreach (self::VARIABLES as $name) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            $this->setVariable($name, $values[$name] ?? null);
        }

        try {
            return require base_path('config/database.php');
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
}
