<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * A regra das senhas do sistema (Password::defaults(), no mínimo 8
 * caracteres, a mesma do cadastro, da troca e da redefinição de senha) para a
 * senha que um seeder recebe do ambiente: o AdminUserSeeder e o DemoSeeder não
 * criam login com uma senha que o sistema recusaria em qualquer outro caminho.
 */
trait ChecksPasswordRule
{
    /**
     * A mensagem da recusa, com o nome da variável de ambiente no lugar do
     * campo (ex.: "O campo ADMIN_PASSWORD deve ter pelo menos 8 caracteres."),
     * ou null quando a senha serve. A mensagem nunca repete a senha.
     */
    protected function passwordRuleError(#[\SensitiveParameter] string $password, string $variable): ?string
    {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['string', Password::defaults()]],
            [],
            ['password' => $variable],
        );

        return $validator->fails() ? $validator->errors()->first('password') : null;
    }
}
