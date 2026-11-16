<?php

namespace Tests\Support;

use PDOException;
use PDOStatement;

/**
 * Comando do PDO que roda no banco e, logo depois, dá o erro de conexão perdida
 * do MySQL (2006), como a leitura que desiste no DB_READ_TIMEOUT depois que o
 * banco já gravou. Só falha o primeiro comando cujo SQL (sem aspas nem crases)
 * casa com o $pattern. Quem liga e desliga é o loseConnectionAfter() do
 * tests/TestCase.php.
 */
final class LostConnectionStatement extends PDOStatement
{
    /**
     * Expressão regular do comando que vai falhar; null desliga.
     */
    public static ?string $pattern = null;

    public function execute(?array $params = null): bool
    {
        $executed = parent::execute($params);

        if (self::$pattern !== null && preg_match(self::$pattern, str_replace(['"', '`'], '', $this->queryString)) === 1) {
            self::$pattern = null;

            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }

        return $executed;
    }
}
