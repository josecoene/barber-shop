<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Conta de cliente: cadastro, e-mail de confirmação e ligação ao cadastro de
 * cliente, que só acontece depois da confirmação.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'cliente.a3@barberflow.test';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['barberflow.rate_limits.register' => 5]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cliente Teste A3',
            'email' => self::EMAIL,
            'phone' => '67999990000',
            'password' => 'senha-do-cliente',
            'password_confirmation' => 'senha-do-cliente',
        ], $overrides);
    }

    private function register(array $overrides = []): User
    {
        $this->postJson('/api/register', $this->payload($overrides))->assertCreated();

        return User::where('email', $overrides['email'] ?? self::EMAIL)->firstOrFail();
    }

    /**
     * O link de confirmação do e-mail que o usuário recebeu.
     */
    private function verificationUrl(User $user): string
    {
        $url = null;

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        return $url;
    }

    /**
     * "Esqueci minha senha" pela API, com o token que foi no e-mail do usuário.
     */
    private function resetPasswordThroughTheEmail(User $user, string $password): void
    {
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertOk();
    }

    public function test_api_registration_creates_an_unconfirmed_client_account_and_sends_the_email(): void
    {
        $this->postJson('/api/register', $this->payload())
            ->assertCreated()
            ->assertExactJson(['message' => 'Conta criada. Confirme seu e-mail pelo link que enviamos para começar a usar a conta.']);

        $user = User::firstWhere('email', self::EMAIL);

        $this->assertSame(UserRole::Client, $user->role);
        $this->assertSame('Cliente Teste A3', $user->name);
        $this->assertSame('67999990000', $user->phone);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('senha-do-cliente', $user->password));

        // O cadastro de cliente só vem depois da confirmação do e-mail.
        $this->assertSame(0, Client::count());

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_role_sent_in_the_request_is_ignored(): void
    {
        $user = $this->register(['role' => 'admin']);

        $this->assertSame(UserRole::Client, $user->role);
    }

    public function test_web_registration_logs_in_and_shows_the_confirmation_notice(): void
    {
        $this->post('/register', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/email/verify');

        $user = User::firstWhere('email', self::EMAIL);

        $this->assertAuthenticatedAs($user, 'web');
        Notification::assertSentTo($user, VerifyEmail::class);

        // Até confirmar, a conta não abre as telas do cliente.
        $this->get('/account')->assertRedirect('/email/verify');
    }

    public function test_registration_validates_the_fields(): void
    {
        User::create(['name' => 'Já existe', 'email' => self::EMAIL, 'password' => 'outra-senha-123']);

        $this->postJson('/api/register', $this->payload(['password' => 'curta', 'password_confirmation' => 'curta']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Este e-mail já está cadastrado.')
            ->assertJsonPath('errors.password.0', 'O campo senha deve ter pelo menos 8 caracteres.');

        $this->postJson('/api/register', $this->payload(['email' => 'outra@barberflow.test', 'password_confirmation' => 'diferente']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'A confirmação do campo senha não confere.');

        $this->postJson('/api/register', $this->payload(['email' => 'removido+1@barberflow.invalid', 'name' => '', 'phone' => str_repeat('9', 21)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'name', 'phone']);

        $this->assertSame(1, User::count());
    }

    public function test_confirming_the_email_links_the_account_to_the_client_with_the_same_email(): void
    {
        $client = Client::create(['name' => 'Cliente da Barbearia', 'email' => self::EMAIL]);
        $user = $this->register();

        $this->assertNull($client->fresh()->user_id);

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect('/');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());

        $client->refresh();
        $this->assertTrue($client->user()->is($user));
        // O nome que a barbearia cadastrou fica; o telefone da conta entra porque o cadastro não tinha.
        $this->assertSame('Cliente da Barbearia', $client->name);
        $this->assertSame('67999990000', $client->phone);
        $this->assertSame(1, Client::count());

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/user')->assertJsonPath('client_id', $client->id);
    }

    public function test_confirmation_message_reaches_the_account_page(): void
    {
        $this->withoutVite();

        $user = $this->register();

        // O link leva a / (tela inicial), que redireciona para Minha conta: a mensagem passa pelos dois.
        $this->actingAs($user)
            ->followingRedirects()
            ->get($this->verificationUrl($user))
            ->assertOk()
            ->assertSee('Minha conta')
            ->assertSee('E-mail confirmado.');
    }

    public function test_confirming_the_email_keeps_the_phone_the_barbershop_already_had(): void
    {
        $client = Client::create(['name' => 'Cliente da Barbearia', 'email' => self::EMAIL, 'phone' => '67911112222']);
        $user = $this->register();

        $this->actingAs($user)->get($this->verificationUrl($user));

        $this->assertSame('67911112222', $client->fresh()->phone);
    }

    public function test_confirming_the_email_creates_the_client_when_no_record_has_the_email(): void
    {
        Client::create(['name' => 'Outra pessoa', 'email' => 'outra@barberflow.test']);
        $user = $this->register();

        $this->actingAs($user)->get($this->verificationUrl($user))->assertRedirect('/');

        $client = $user->fresh()->client;

        $this->assertSame('Cliente Teste A3', $client->name);
        $this->assertSame(self::EMAIL, $client->email);
        $this->assertSame('67999990000', $client->phone);
        $this->assertSame(2, Client::count());
    }

    public function test_confirming_the_email_does_not_take_a_client_that_already_has_an_account(): void
    {
        Log::spy();

        // O cadastro já tem conta, e o e-mail dele foi trocado depois para o e-mail da conta nova.
        $owner = $this->makeClientUser();
        $client = $owner->client;
        $client->update(['email' => self::EMAIL]);

        $user = $this->register();
        $this->actingAs($user)->get($this->verificationUrl($user))->assertRedirect('/');

        $this->assertTrue($client->fresh()->user()->is($owner));
        $this->assertNull($user->fresh()->client);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context === ['user_id' => $user->id, 'client_id' => $client->id]
                && ! str_contains($message, '@'));
    }

    /**
     * Alguém cria a conta com o e-mail de um cliente da barbearia e com o nome e o telefone dele
     * mesmo. O dono do e-mail recebe "Este e-mail já está cadastrado.", recupera a conta pelo
     * "esqueci minha senha" e confirma o e-mail. O que o outro digitou não pode ir para o cadastro.
     */
    public function test_reset_before_the_confirmation_drops_the_name_and_phone_of_the_registration(): void
    {
        $client = Client::create(['name' => 'Cliente da Barbearia', 'email' => self::EMAIL]);
        $user = $this->register(['name' => 'Nome do Atacante', 'phone' => '67900000000']);

        $this->resetPasswordThroughTheEmail($user, 'senha-do-dono');

        // O nome vira a parte do e-mail antes do @, e o telefone sai.
        $user->refresh();
        $this->assertSame('cliente.a3', $user->name);
        $this->assertNull($user->phone);

        $this->actingAs($user)->get($this->verificationUrl($user))->assertRedirect('/');

        // A conta é ligada ao cadastro da barbearia, que fica sem o telefone de quem criou a conta.
        $client->refresh();
        $this->assertTrue($client->user()->is($user));
        $this->assertSame('Cliente da Barbearia', $client->name);
        $this->assertNull($client->phone);
    }

    public function test_reset_before_the_confirmation_keeps_the_registration_data_out_of_a_new_client(): void
    {
        $user = $this->register(['name' => 'Nome do Atacante', 'phone' => '67900000000']);

        $this->resetPasswordThroughTheEmail($user, 'senha-do-dono');

        $this->actingAs($user->fresh())->get($this->verificationUrl($user))->assertRedirect('/');

        $client = $user->fresh()->client;

        $this->assertSame('cliente.a3', $client->name);
        $this->assertSame(self::EMAIL, $client->email);
        $this->assertNull($client->phone);
    }

    public function test_reset_after_the_confirmation_keeps_the_name_and_phone(): void
    {
        $user = $this->register();
        $this->actingAs($user)->get($this->verificationUrl($user))->assertRedirect('/');

        $this->resetPasswordThroughTheEmail($user->fresh(), 'senha-nova-123');

        $user->refresh();
        $this->assertSame('Cliente Teste A3', $user->name);
        $this->assertSame('67999990000', $user->phone);
        $this->assertSame('67999990000', $user->client->phone);
    }

    public function test_invalid_or_expired_confirmation_links_are_refused(): void
    {
        // As recusas abrem a página de erro 403, que usa o layout com o Vite: sem isto, o teste
        // depende do npm run build (o CI roda os testes sem o build).
        $this->withoutVite();

        $user = $this->register();
        $url = $this->verificationUrl($user);

        // Link de outro e-mail.
        $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('outro@barberflow.test')]);
        $this->actingAs($user)->get($wrongHash)->assertForbidden();

        // Assinatura adulterada.
        $this->actingAs($user)->get($url.'0')->assertForbidden();

        // Link de outra conta, aberto por quem está logado. Trocar de usuário é um login novo,
        // então a sessão começa limpa (senão o auth.session derruba a sessão do usuário anterior).
        $other = $this->register(['email' => 'outra@barberflow.test']);
        $this->flushSession();
        $this->actingAs($other)->get($url)->assertForbidden();

        // Link vencido (vale 60 minutos).
        $this->travel(61)->minutes();
        $this->flushSession();
        $this->actingAs($user)->get($url)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSame(0, Client::count());
    }

    public function test_registration_is_limited_per_ip(): void
    {
        config(['barberflow.rate_limits.register' => 2]);

        $this->postJson('/api/register', $this->payload(['email' => 'a@barberflow.test']))->assertCreated();
        $this->postJson('/api/register', $this->payload(['email' => 'b@barberflow.test']))->assertCreated();

        $this->postJson('/api/register', $this->payload(['email' => 'c@barberflow.test']))
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertDatabaseMissing('users', ['email' => 'c@barberflow.test']);
    }

    public function test_api_resends_the_confirmation_email(): void
    {
        $user = Sanctum::actingAs($this->makeClientUser(verified: false));

        $this->postJson('/api/email/verification-notification')
            ->assertStatus(202)
            ->assertExactJson(['message' => 'Enviamos um novo link de confirmação para o seu e-mail.']);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_api_does_not_resend_when_the_email_is_already_confirmed(): void
    {
        $user = Sanctum::actingAs($this->makeClientUser());

        $this->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertExactJson(['message' => 'Seu e-mail já está confirmado.']);

        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_screen_resends_the_confirmation_email(): void
    {
        $user = $this->makeClientUser(verified: false);

        $this->actingAs($user)
            ->from('/email/verify')
            ->post('/email/verification-notification')
            ->assertRedirect('/email/verify')
            ->assertSessionHas('status', 'Enviamos um novo link de confirmação para o seu e-mail.');

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
