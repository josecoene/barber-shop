<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * O usuário de teste (test@example.com, com a senha de sempre) é o login de
     * teste das telas e da API, com o perfil admin. A senha conhecida é um risco
     * registrado no README (Limitações conhecidas) e no docs/ARQUITETURA.md
     * (Limitações e riscos conhecidos): em produção o admin vem do
     * AdminUserSeeder, com a senha definida por quem instala, e o deploy de
     * produção não roda seed.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password')],
        );

        // Sem perfil, o usuário não entra. Ganha o de admin sem tocar na senha; quem já tem
        // perfil fica como está. O e-mail já nasce confirmado, como o de todo admin criado pelo sistema.
        if ($user->role === null) {
            $user->forceFill([
                'role' => UserRole::Admin,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        $this->call([
            AdminUserSeeder::class,
            ServiceSeeder::class,
        ]);
    }
}
