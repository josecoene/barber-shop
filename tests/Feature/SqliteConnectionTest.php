<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O SQLite do ambiente local recebe várias requisições ao mesmo tempo (o servidor
 * roda com vários workers e as telas da agenda fazem chamadas em paralelo), e
 * cada chamada da API grava o contador do limite de requisições. Com o padrão
 * (DEFERRED e sem espera), duas gravações cruzadas davam "database is locked" e
 * erro 500. A conexão precisa esperar a vez e pegar a trava de escrita no começo
 * da transação.
 */
class SqliteConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_waits_for_the_lock_instead_of_failing(): void
    {
        $config = config('database.connections.sqlite');

        $this->assertSame('IMMEDIATE', $config['transaction_mode']);
        $this->assertSame(5000, $config['busy_timeout']);

        // A suíte roda no SQLite (phpunit.xml): o busy_timeout chega de fato à conexão.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertSame(5000, (int) DB::selectOne('PRAGMA busy_timeout')->timeout);
        }
    }
}
