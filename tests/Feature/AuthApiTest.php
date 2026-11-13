<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Login, token, logout, usuário logado e troca de senha pela API.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Os valores padrão, mesmo que o .env de quem roda os testes mude algum.
        config([
            'sanctum.expiration' => 480,
            'sanctum.token_prefix' => 'barberflow_',
            'barberflow.rate_limits.api' => 120,
            'barberflow.rate_limits.login' => 5,
            'barberflow.rate_limits.login_per_ip' => 20,
        ]);
    }

    private function login(User $user, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => $password]);
    }

    /**
     * Esquece o usuário que o teste já autenticou, para a próxima chamada depender
     * só do token enviado (fora dos testes, cada requisição começa do zero).
     */
    private function bearer(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_login_returns_a_prefixed_bearer_token_that_expires(): void
    {
        $this->freezeSecond();
        $admin = $this->makeAdmin();

        $response = $this->postJson('/api/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
            'device_name' => 'Postman',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', now()->addMinutes(480)->toJSON())
            ->assertJsonPath('user', [
                'id' => $admin->id,
                'name' => 'Admin Teste',
                'email' => $admin->email,
                'role' => 'admin',
            ]);

        $token = $response->json('token');

        $this->assertMatchesRegularExpression('/^\d+\|barberflow_[A-Za-z0-9]{40}[0-9a-f]{8}$/', $token);
        $this->assertSame('Postman', PersonalAccessToken::findToken($token)->name);

        $this->getJson('/api/user', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('id', $admin->id);
    }

    public function test_wrong_password_returns_422_in_portuguese(): void
    {
        $admin = $this->makeAdmin();

        $this->login($admin, 'senha-errada')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'E-mail ou senha incorretos.')
            ->assertJsonPath('errors.email.0', 'E-mail ou senha incorretos.')
            ->assertJsonMissingPath('token');

        // E-mail que não existe recebe a mesma resposta: o login não conta quem tem conta.
        $this->postJson('/api/login', ['email' => 'ninguem@barberflow.test', 'password' => 'senha-errada'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'E-mail ou senha incorretos.');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'O campo e-mail é obrigatório.')
            ->assertJsonPath('errors.password.0', 'O campo senha é obrigatório.');
    }

    public function test_user_without_role_is_refused(): void
    {
        $user = User::create(['name' => 'Sem Perfil', 'email' => 'sem.perfil@barberflow.test', 'password' => self::PASSWORD]);

        $this->login($user)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Esta conta não tem acesso.');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_barber_without_an_active_record_is_refused(): void
    {
        $barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $user = $this->makeBarberUser($barber);

        // Excluído fora da API (pela API, o login seria apagado junto): o login sobra, sem cadastro ativo.
        $barber->delete();

        $this->login($user)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Esta conta não tem acesso.');

        $orphan = User::create(['name' => 'Sem Cadastro', 'email' => 'sem.cadastro@barberflow.test', 'password' => self::PASSWORD]);
        $orphan->forceFill(['role' => UserRole::Barber])->save();

        $this->login($orphan)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Esta conta não tem acesso.');
    }

    public function test_client_whose_record_was_deleted_is_refused(): void
    {
        $client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $user = $this->makeClientUser($client);

        $client->delete();

        $this->login($user)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Esta conta não tem acesso.');
    }

    public function test_new_client_account_logs_in_before_confirming_the_email(): void
    {
        // A conta recém-criada ainda não tem cadastro de cliente (ele nasce na confirmação do e-mail).
        $user = new User(['name' => 'Conta Nova', 'email' => 'conta.nova@barberflow.test', 'password' => self::PASSWORD]);
        $user->forceFill(['role' => UserRole::Client])->save();

        $this->login($user)
            ->assertOk()
            ->assertJsonPath('user.role', 'client');
    }

    public function test_login_is_blocked_after_five_refused_attempts(): void
    {
        $this->freezeSecond();
        $admin = $this->makeAdmin();

        foreach (range(1, 5) as $attempt) {
            $this->login($admin, 'senha-errada')->assertUnprocessable();
        }

        // Na sexta tentativa, nem a senha certa entra até o minuto passar. A resposta traz os mesmos
        // cabeçalhos dos outros 429, com os números deste bloqueio (e não os do limite por IP, que ainda tem folga).
        $this->login($admin)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Muitas tentativas de login. Tente de novo em 60 segundos.')
            ->assertJsonPath('errors.email.0', 'Muitas tentativas de login. Tente de novo em 60 segundos.')
            ->assertJsonMissingPath('token')
            ->assertHeader('Retry-After', 60)
            ->assertHeader('X-RateLimit-Limit', 5)
            ->assertHeader('X-RateLimit-Remaining', 0);

        $this->travel(61)->seconds();

        $this->login($admin)->assertOk();
    }

    public function test_successful_login_resets_the_count_of_refused_attempts(): void
    {
        $admin = $this->makeAdmin();

        foreach (range(1, 4) as $attempt) {
            $this->login($admin, 'senha-errada')->assertUnprocessable();
        }

        $this->login($admin)->assertOk();

        foreach (range(1, 5) as $attempt) {
            $this->login($admin, 'senha-errada')->assertUnprocessable();
        }
    }

    public function test_logins_from_one_ip_are_limited_even_with_different_emails(): void
    {
        config(['barberflow.rate_limits.login_per_ip' => 3]);

        foreach (range(1, 3) as $number) {
            $this->postJson('/api/login', ['email' => "pessoa{$number}@barberflow.test", 'password' => 'qualquer-senha'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/login', ['email' => 'pessoa4@barberflow.test', 'password' => 'qualquer-senha'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Muitas requisições. Tente de novo em'));
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->login($admin)->json('token');
        $other = $this->login($admin)->json('token');

        $this->postJson('/api/logout', [], $this->bearer($token))->assertNoContent();

        $this->getJson('/api/user', $this->bearer($token))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Não autenticado.']);

        $this->getJson('/api/user', $this->bearer($other))->assertOk();
    }

    public function test_token_expires_after_480_minutes(): void
    {
        $this->freezeSecond();
        $token = $this->login($this->makeAdmin())->json('token');

        $this->travel(479)->minutes();
        $this->getJson('/api/user', $this->bearer($token))->assertOk();

        $this->travel(2)->minutes();
        $this->getJson('/api/user', $this->bearer($token))->assertUnauthorized();
    }

    public function test_user_endpoint_shows_the_role_and_the_linked_record(): void
    {
        $barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        Sanctum::actingAs($this->makeBarberUser($barber));

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('role', 'barber')
            ->assertJsonPath('barber_id', $barber->id)
            ->assertJsonPath('client_id', null)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');

        $client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        Sanctum::actingAs($this->makeClientUser($client));

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('role', 'client')
            ->assertJsonPath('barber_id', null)
            ->assertJsonPath('client_id', $client->id);
    }

    public function test_changes_the_password_and_ends_the_other_tokens(): void
    {
        $admin = $this->makeAdmin();
        $token = $this->login($admin)->json('token');
        $other = $this->login($admin)->json('token');

        $this->putJson('/api/user/password', [
            'current_password' => self::PASSWORD,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ], $this->bearer($token))->assertNoContent();

        $this->assertTrue(Hash::check('senha-nova-123', $admin->fresh()->password));

        $this->getJson('/api/user', $this->bearer($token))->assertOk();
        $this->getJson('/api/user', $this->bearer($other))->assertUnauthorized();
    }

    public function test_password_change_needs_the_right_current_password(): void
    {
        $admin = Sanctum::actingAs($this->makeAdmin());

        $this->putJson('/api/user/password', [
            'current_password' => 'senha-errada',
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', 'A senha está incorreta.');

        $this->putJson('/api/user/password', [
            'current_password' => self::PASSWORD,
            'password' => 'curta',
            'password_confirmation' => 'outra',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check(self::PASSWORD, $admin->fresh()->password));
    }
}
