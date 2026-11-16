<?php

namespace App\Exceptions;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * E-mail que já está cadastrado: o mesmo 422 da regra unique, no campo email,
 * com a mensagem dela ("Este e-mail já está cadastrado.", em
 * lang/pt_BR/validation.php).
 *
 * A regra unique do validate() lê o banco antes da gravação. Dois pedidos com o
 * mesmo e-mail no mesmo instante passam os dois por ela, e o índice unique do
 * banco recusa o segundo. O guard() troca essa recusa pelo 422 da regra, em vez
 * de um erro 500, como o PaymentRecorder faz com o pagamento repetido. Nada fica
 * gravado em dobro: quem barrou foi o banco. Usado no cadastro e na edição de
 * clientes e de barbeiros (com o login do barbeiro, que grava users.email) e no
 * cadastro de conta de cliente.
 */
class EmailTakenException extends ValidationException
{
    public static function make(): self
    {
        return self::withMessages(['email' => __('validation.custom.email.unique')]);
    }

    /**
     * Roda a gravação e devolve o que ela devolver. A recusa do índice unique de
     * um e-mail vira este 422; a de outro índice (como o user_id) não é e-mail em
     * uso e continua sendo um erro.
     *
     * Chame por fora do DB::transaction(): assim a transação já foi desfeita
     * quando o 422 sai, e nada do pedido fica no banco.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     *
     * @throws self
     */
    public static function guard(Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            throw self::isEmailIndex($e) ? self::make() : $e;
        }
    }

    /**
     * O SQLite diz a coluna recusada (UNIQUE constraint failed: clients.email), e
     * o MySQL, o nome do índice (clients_email_unique). Os índices de e-mail das
     * tabelas users, clients e barbers seguem o nome padrão do Laravel.
     */
    private static function isEmailIndex(UniqueConstraintViolationException $e): bool
    {
        return in_array('email', $e->columns, true)
            || ($e->index !== null && str_ends_with($e->index, '_email_unique'));
    }
}
