<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * A trava do tests/TestCase.php: o RefreshDatabase só apaga banco de teste
 * (SQLite em memória ou banco com "test" no nome).
 */
class TestDatabaseGuardTest extends TestCase
{
    use RefreshDatabase;

    public static function databasesThatAreNotForTests(): array
    {
        // Destinos que não existem, para nada ser apagado mesmo se a trava falhar.
        return [
            'arquivo do SQLite' => ['database', '/app/storage/framework/nao-existe/barberflow.sqlite'],
            // O DB_URL vale mais que o database da conexão. O host .invalid nunca resolve (RFC 2606): se a
            // trava falhar, o teste dá erro de conexão em vez de apagar o banco de desenvolvimento.
            'banco no DB_URL' => ['url', 'mysql://barberflow.invalid:1/barberflow'],
        ];
    }

    #[DataProvider('databasesThatAreNotForTests')]
    public function test_refuses_to_refresh_a_database_that_is_not_for_tests(string $key, string $value): void
    {
        $connection = config('database.default');
        config(["database.connections.{$connection}.{$key}" => $value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('que não parece ser de teste');

        $this->setUpTraits();
    }

    public function test_the_test_configuration_uses_a_test_database(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $this->assertTrue($database === ':memory:' || str_contains(strtolower($database), 'test'), $database);
    }
}
