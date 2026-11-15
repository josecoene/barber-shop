<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sem ADMIN_* (mesmo que o .env de quem roda os testes tenha), o AdminUserSeeder
     * não cria ninguém. Os testes do admin inicial ligam os valores com config([...]).
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'barberflow.admin.name' => 'Administrador',
            'barberflow.admin.email' => null,
            'barberflow.admin.password' => null,
        ]);
    }

    public function test_seeding_twice_does_not_duplicate_records(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(4, Service::count());
        $this->assertSame(1, User::count());
    }

    public function test_seeding_again_restores_the_sample_services_and_keeps_the_others(): void
    {
        $this->seed();

        Service::where('name', 'Corte de cabelo')->update(['price' => 45.00, 'duration' => 35]);
        Service::where('name', 'Acabamento')->update(['status' => 'inactive']);
        Service::create(['name' => 'Pigmentação', 'duration' => 40, 'price' => 50.00, 'status' => 'inactive']);

        $this->seed();

        $haircut = Service::firstWhere('name', 'Corte de cabelo');
        $this->assertEquals(40.0, (float) $haircut->price);
        $this->assertEquals(30, (int) $haircut->duration);
        $this->assertSame('active', Service::firstWhere('name', 'Acabamento')->status);

        $custom = Service::firstWhere('name', 'Pigmentação');
        $this->assertEquals(50.0, (float) $custom->price);
        $this->assertSame('inactive', $custom->status);
        $this->assertSame(5, Service::count());
    }

    public function test_seeds_the_test_user_as_admin_with_the_same_password(): void
    {
        $this->seed();

        $user = User::firstWhere('email', 'test@example.com');

        $this->assertSame('Test User', $user->name);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_test_user_without_role_becomes_admin_and_keeps_its_password(): void
    {
        // Como no banco de quem já rodou o seed antes do login existir.
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => 'outra-senha-123']);
        $hash = $user->password;

        $this->seed();

        $user->refresh();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertSame($hash, $user->password);
    }

    public function test_test_user_with_another_role_keeps_it(): void
    {
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => 'outra-senha-123']);
        $user->forceFill(['role' => UserRole::Barber])->save();

        $this->seed();

        $this->assertSame(UserRole::Barber, $user->fresh()->role);
    }

    public function test_admin_seeder_without_password_only_warns(): void
    {
        config(['barberflow.admin.email' => 'dono@barberflow.test']);

        $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
            ->expectsOutputToContain('Administrador inicial não criado')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'dono@barberflow.test']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function passwordsBelowTheRule(): array
    {
        return [
            '1 caractere' => ['1'],
            '7 caracteres' => ['curta12'],
        ];
    }

    #[DataProvider('passwordsBelowTheRule')]
    public function test_admin_seeder_refuses_a_password_below_the_rule_of_the_accounts(string $password): void
    {
        config([
            'barberflow.admin.email' => 'dono@barberflow.test',
            'barberflow.admin.password' => $password,
        ]);

        // A mesma regra do cadastro, da troca e da redefinição de senha (Password::defaults()).
        $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
            ->expectsOutputToContain('Administrador inicial não criado. O campo ADMIN_PASSWORD deve ter pelo menos 8 caracteres.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'dono@barberflow.test']);
    }

    public function test_admin_seeder_creates_the_admin_from_the_config(): void
    {
        config([
            'barberflow.admin.name' => 'Dono da Barbearia',
            'barberflow.admin.email' => 'dono@barberflow.test',
            'barberflow.admin.password' => 'senha-do-dono-123',
        ]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::firstWhere('email', 'dono@barberflow.test');

        $this->assertSame('Dono da Barbearia', $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('senha-do-dono-123', $admin->password));
    }

    public function test_admin_seeder_never_changes_an_existing_user(): void
    {
        $user = User::create(['name' => 'Já existia', 'email' => 'dono@barberflow.test', 'password' => 'senha-antiga-123']);
        $user->forceFill(['role' => UserRole::Client])->save();
        $hash = $user->password;

        config([
            'barberflow.admin.email' => 'dono@barberflow.test',
            'barberflow.admin.password' => 'senha-do-dono-123',
        ]);

        $this->seed(AdminUserSeeder::class);

        $user->refresh();
        $this->assertSame($hash, $user->password);
        $this->assertSame(UserRole::Client, $user->role);
        $this->assertSame(1, User::count());
    }
}
