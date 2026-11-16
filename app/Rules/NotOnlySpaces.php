<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Recusa texto só de espaços (ou tabulações e quebras de linha) num campo
 * opcional, como a senha do login do barbeiro. Sem valor (null) continua aceito.
 *
 * O validador do Laravel trata texto só de espaços como vazio e, nesse caso, só
 * roda as regras implícitas (como a required). As outras, como o mínimo de 8
 * caracteres da senha, nem rodam, e a senha "        " passaria. Esta regra é
 * implícita para rodar mesmo assim.
 */
class NotOnlySpaces implements ValidationRule
{
    /**
     * Regra implícita: o Laravel roda mesmo quando o valor é vazio ou só de espaços.
     */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $value !== '' && trim($value) === '') {
            $fail('validation.not_only_spaces')->translate();
        }
    }
}
