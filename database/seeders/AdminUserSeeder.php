<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Administrador inicial, com nome, e-mail e senha de ADMIN_NAME, ADMIN_EMAIL e
 * ADMIN_PASSWORD (lidos pelo config/barberflow.php).
 *
 * php artisan db:seed --class=AdminUserSeeder (em produção, com --force)
 *
 * - Sem e-mail ou sem senha: só avisa e não cria nada.
 * - Usuário com esse e-mail ainda não existe: cria o admin, com o e-mail já
 *   confirmado.
 * - Já existe: não muda nada. A senha de quem já existe nunca é trocada, e um
 *   usuário que não é admin não vira admin (alguém poderia ter criado uma conta
 *   com o e-mail do admin antes do seed).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('barberflow.admin.email');
        $password = config('barberflow.admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn('Administrador inicial não criado: defina ADMIN_EMAIL e ADMIN_PASSWORD no .env.');

            return;
        }

        $existing = User::firstWhere('email', $email);

        if ($existing !== null) {
            $message = $existing->isAdmin()
                ? 'O administrador inicial já existe; nada foi alterado (nem a senha).'
                : 'Já existe um usuário com o ADMIN_EMAIL e ele não é admin; nada foi alterado.';

            $this->command?->warn($message);

            return;
        }

        $admin = new User([
            'name' => config('barberflow.admin.name'),
            'email' => $email,
            'password' => $password,
        ]);

        $admin->forceFill([
            'role' => UserRole::Admin,
            'email_verified_at' => now(),
        ])->save();

        $this->command?->info('Administrador inicial criado.');
    }
}
