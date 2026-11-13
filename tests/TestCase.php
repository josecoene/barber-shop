<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Senha de todos os usuários criados pelos helpers abaixo.
     */
    protected const PASSWORD = 'senha-de-teste-123';

    /**
     * Admin com o e-mail confirmado.
     */
    protected function makeAdmin(): User
    {
        return $this->makeUser(UserRole::Admin, 'Admin Teste', 'admin.'.Str::lower(Str::random(8)).'@barberflow.test');
    }

    /**
     * Login de barbeiro ligado ao cadastro informado ou a um cadastro novo, com o
     * mesmo nome e e-mail, como o BarberController faz.
     */
    protected function makeBarberUser(?Barber $barber = null): User
    {
        $barber ??= Barber::create([
            'name' => 'Barbeiro Teste',
            'email' => 'barbeiro.'.Str::lower(Str::random(8)).'@barberflow.test',
        ]);

        $user = $this->makeUser(UserRole::Barber, $barber->name, $barber->email);

        $barber->user()->associate($user)->save();

        return $user;
    }

    /**
     * Conta de cliente ligada ao cadastro informado ou a um cadastro novo. Com
     * $verified false, a conta fica com o e-mail ainda não confirmado.
     */
    protected function makeClientUser(?Client $client = null, bool $verified = true): User
    {
        $client ??= Client::create([
            'name' => 'Cliente Teste',
            'email' => 'cliente.'.Str::lower(Str::random(8)).'@barberflow.test',
        ]);

        $user = $this->makeUser(UserRole::Client, $client->name, $client->email, $verified);

        $client->user()->associate($user)->save();

        return $user;
    }

    /**
     * Autentica a API como admin (Sanctum::actingAs, sem token de verdade).
     */
    protected function actingAsAdmin(): User
    {
        return Sanctum::actingAs($this->makeAdmin());
    }

    protected function actingAsBarber(?Barber $barber = null): User
    {
        return Sanctum::actingAs($this->makeBarberUser($barber));
    }

    protected function actingAsClient(?Client $client = null, bool $verified = true): User
    {
        return Sanctum::actingAs($this->makeClientUser($client, $verified));
    }

    /**
     * O perfil vai por forceFill, como no código da aplicação: role não é fillable.
     */
    private function makeUser(UserRole $role, string $name, string $email, bool $verified = true): User
    {
        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
        ]);

        $user->forceFill([
            'role' => $role,
            'email_verified_at' => $verified ? now() : null,
        ])->save();

        return $user;
    }
}
