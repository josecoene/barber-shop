<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * E-mail que um cliente ou barbeiro recebe no lugar do real quando é excluído.
 *
 * O domínio .invalid é reservado e nunca recebe mensagem. Trocar o e-mail libera
 * o original no índice unique do banco, para a pessoa poder ser cadastrada de novo.
 * Usado por models com SoftDeletes e coluna email única.
 */
trait ReleasesEmailOnDelete
{
    /**
     * Normalmente devolve removido+{id}@barberflow.invalid.
     *
     * A validação recusa e-mails @barberflow.invalid, mas o MySQL compara texto sem
     * diferenciar acento: "removido+1@barberflow.ínvalid" passa na validação e, para o
     * índice unique, é igual a "removido+1@barberflow.invalid". Por isso a pergunta
     * "esse endereço já está em uso?" vai para o próprio banco, que compara do mesmo
     * jeito que o índice. Se estiver em uso, o endereço ganha um sufixo aleatório, e a
     * exclusão não trava num erro 500.
     */
    protected function releasedEmail(): string
    {
        $email = "removido+{$this->getKey()}@barberflow.invalid";

        $taken = static::withTrashed()
            ->whereKeyNot($this->getKey())
            ->where('email', $email)
            ->exists();

        if (! $taken) {
            return $email;
        }

        return 'removido+'.$this->getKey().'.'.Str::lower(Str::random(12)).'@barberflow.invalid';
    }
}
