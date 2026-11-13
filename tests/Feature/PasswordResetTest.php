<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Esqueci minha senha" pela API e pelas telas, com o broker de senha do Laravel.
 * Os usuários são criados em cada teste (o usuário de teste do seeder não muda).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const SENT = 'Se o e-mail estiver cadastrado, você vai receber o link para redefinir a senha.';

    private const INVALID_TOKEN = 'Este link para redefinir a senha é inválido ou já expirou.';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['barberflow.rate_limits.password_reset' => 5]);
    }

    /**
     * Pede o link pela API e devolve o token que foi no e-mail.
     */
    private function requestToken(User $user): string
    {
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        return $token;
    }

    public function test_reset_link_is_sent_by_email(): void
    {
        $user = $this->makeClientUser();

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson(['message' => self::SENT]);

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_unknown_email_gets_the_same_answer(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'ninguem@barberflow.test'])
            ->assertOk()
            ->assertExactJson(['message' => self::SENT]);

        Notification::assertNothingSent();
    }

    public function test_password_is_reset_with_a_valid_token(): void
    {
        $user = $this->makeAdmin();
        $user->createToken('celular');
        DB::table('sessions')->insert([
            'id' => 'sessao-antiga',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $token = $this->requestToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ])
            ->assertOk()
            ->assertExactJson(['message' => 'Sua senha foi redefinida.']);

        $this->assertTrue(Hash::check('senha-nova-123', $user->fresh()->password));

        // Quem tinha a senha antiga sai: os tokens e as sessões abertas acabam.
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        // O link vale uma vez só.
        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'outra-senha-123',
            'password_confirmation' => 'outra-senha-123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', self::INVALID_TOKEN);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'senha-nova-123'])->assertOk();
    }

    public function test_invalid_token_is_refused(): void
    {
        $user = $this->makeAdmin();
        $this->requestToken($user);

        $this->postJson('/api/reset-password', [
            'token' => 'token-que-nao-existe',
            'email' => $user->email,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', self::INVALID_TOKEN);

        // E-mail sem conta recebe a mesma resposta.
        $this->postJson('/api/reset-password', [
            'token' => 'token-que-nao-existe',
            'email' => 'ninguem@barberflow.test',
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', self::INVALID_TOKEN);

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_expired_token_is_refused(): void
    {
        $user = $this->makeAdmin();
        $token = $this->requestToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', self::INVALID_TOKEN);
    }

    public function test_new_password_needs_confirmation_and_8_characters(): void
    {
        $user = $this->makeAdmin();
        $token = $this->requestToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'curta',
            'password_confirmation' => 'outra',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_screens_send_the_link_and_reset_the_password(): void
    {
        $user = $this->makeClientUser();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', self::SENT);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'senha-nova-123',
            'password_confirmation' => 'senha-nova-123',
        ])
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Sua senha foi redefinida.');

        $this->assertTrue(Hash::check('senha-nova-123', $user->fresh()->password));
    }

    public function test_requests_are_limited_per_email_and_ip(): void
    {
        config(['barberflow.rate_limits.password_reset' => 2]);

        $this->postJson('/api/forgot-password', ['email' => 'ana@barberflow.test'])->assertOk();
        $this->postJson('/api/forgot-password', ['email' => 'ana@barberflow.test'])->assertOk();

        $this->postJson('/api/forgot-password', ['email' => 'ana@barberflow.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // Outro e-mail tem a contagem dele.
        $this->postJson('/api/forgot-password', ['email' => 'bruno@barberflow.test'])->assertOk();
    }
}
