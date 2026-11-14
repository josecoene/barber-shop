<?php

namespace Tests;

use App\Enums\UserRole;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Senha de todos os usuários criados pelos helpers abaixo.
     */
    protected const PASSWORD = 'senha-de-teste-123';

    /**
     * Regras da agenda que os testes supõem: os padrões do config/barberflow.php.
     * Ficam fixas aqui para nem o .env local nem uma variável de ambiente (do
     * container ou do CI) mudarem o resultado da suíte. O teste que precisa de
     * outro valor muda com config([...]).
     */
    private const AGENDA_CONFIG = [
        'barberflow.business' => [
            'opening' => '09:00',
            'closing' => '19:00',
            'slot_minutes' => 30,
            'days' => [1, 2, 3, 4, 5, 6],
        ],
        'barberflow.booking_days_ahead' => 60,
        'barberflow.client_cancel_min_hours' => 2,
        'barberflow.client_max_active_appointments' => 3,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(self::AGENDA_CONFIG);
    }

    /**
     * O RefreshDatabase apaga e recria o banco a cada execução. Antes dele, confere
     * que o banco é de teste: SQLite em memória (phpunit.xml) ou um banco com "test"
     * no nome (phpunit.mysql.xml). Assim a configuração em cache (php artisan
     * optimize), que faz os testes ignorarem o phpunit.xml, ou um DB_DATABASE de
     * outro ambiente no ambiente do processo não apagam um banco de verdade.
     */
    protected function setUpTraits()
    {
        // Lê da configuração, sem abrir conexão. O DB_URL, quando existe, vale mais que o
        // database da conexão, e o ConfigurationUrlParser faz a mesma conta que o Laravel.
        $connection = (string) config('database.default');
        $config = (new ConfigurationUrlParser)->parseConfiguration((array) config("database.connections.{$connection}"));
        $database = (string) ($config['database'] ?? '');

        if ($database !== ':memory:' && ! str_contains(Str::lower(basename($database)), 'test')) {
            throw new RuntimeException(
                "Os testes apagam o banco [{$database}], que não parece ser de teste. Use o SQLite em memória do "
                .'phpunit.xml ou um banco com "test" no nome, e rode php artisan config:clear se a configuração estiver em cache.'
            );
        }

        return parent::setUpTraits();
    }

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
