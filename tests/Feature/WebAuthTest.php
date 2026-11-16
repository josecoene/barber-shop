<?php

namespace Tests\Feature;

use App\Models\Barber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Login pelas telas (sessão), tela inicial por perfil e quem abre cada tela.
 */
class WebAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'barberflow.rate_limits.login' => 5,
            'barberflow.rate_limits.login_per_ip' => 20,
        ]);
    }

    public function test_visitor_is_sent_to_the_login(): void
    {
        foreach (['/', '/dashboard', '/appointments', '/payments', '/barbers', '/account', '/booking', '/my-appointments'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public static function homes(): array
    {
        return [
            'admin vai para o dashboard' => ['makeAdmin', '/dashboard'],
            'barbeiro vai para a agenda' => ['makeBarberUser', '/appointments'],
            'cliente vai para Meus horários' => ['makeClientUser', '/my-appointments'],
        ];
    }

    #[DataProvider('homes')]
    public function test_login_takes_each_profile_to_its_home(string $makeUser, string $home): void
    {
        /** @var User $user */
        $user = $this->{$makeUser}();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user, 'web');

        $this->get('/')->assertRedirect($home);
    }

    public function test_login_returns_to_the_page_the_visitor_tried_to_open(): void
    {
        $admin = $this->makeAdmin();

        $this->get('/services')->assertRedirect('/login');

        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertRedirect('/services');
    }

    public function test_wrong_password_and_refused_accounts_stay_on_the_login(): void
    {
        $admin = $this->makeAdmin();

        $this->from('/login')
            ->post('/login', ['email' => $admin->email, 'password' => 'senha-errada'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'E-mail ou senha incorretos.']);

        $barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $barberUser = $this->makeBarberUser($barber);
        $barber->delete();

        $this->from('/login')
            ->post('/login', ['email' => $barberUser->email, 'password' => self::PASSWORD])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Esta conta não tem acesso.']);

        $this->assertGuest('web');
    }

    public function test_login_is_blocked_after_five_refused_attempts(): void
    {
        $admin = $this->makeAdmin();

        foreach (range(1, 5) as $attempt) {
            $this->from('/login')->post('/login', ['email' => $admin->email, 'password' => 'senha-errada']);
        }

        $this->from('/login')
            ->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertStringStartsWith('Muitas tentativas de login.', session('errors')->first('email'));
        $this->assertGuest('web');
    }

    public function test_remember_me_sets_the_remember_cookie(): void
    {
        $admin = $this->makeAdmin();

        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'remember' => 'on'])
            ->assertRedirect('/')
            ->assertCookie(Auth::guard('web')->getRecallerName());
    }

    public function test_logged_user_opening_the_login_goes_home(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/login')
            ->assertRedirect('/');
    }

    public function test_barber_gets_403_on_the_admin_pages(): void
    {
        $this->actingAs($this->makeBarberUser());

        $this->get('/dashboard')->assertForbidden();
        $this->get('/barbers')->assertForbidden();
        $this->get('/services')->assertForbidden();
        $this->get('/clients')->assertOk();
        $this->get('/appointments')->assertOk();
        $this->get('/payments')->assertOk();
    }

    public function test_client_gets_403_on_the_staff_pages(): void
    {
        $this->actingAs($this->makeClientUser());

        foreach (['/dashboard', '/appointments', '/appointments/create', '/payments', '/clients', '/barbers', '/services'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public static function staffProfiles(): array
    {
        return [
            'admin' => ['makeAdmin'],
            'barbeiro' => ['makeBarberUser'],
        ];
    }

    /**
     * Agendar do cliente e Meus horários são do cliente, que agenda para si. A
     * barbearia agenda pela tela Agendar e acompanha pela Agenda.
     */
    #[DataProvider('staffProfiles')]
    public function test_staff_gets_403_on_the_client_pages(string $makeUser): void
    {
        $this->actingAs($this->{$makeUser}());

        $this->get('/booking')->assertForbidden();
        $this->get('/my-appointments')->assertForbidden();
    }

    public function test_client_opens_the_own_pages(): void
    {
        $this->actingAs($this->makeClientUser());

        foreach (['/booking', '/my-appointments', '/account'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_client_that_has_not_confirmed_the_email_is_sent_to_the_notice(): void
    {
        $this->actingAs($this->makeClientUser(verified: false));

        foreach (['/account', '/booking', '/my-appointments'] as $uri) {
            $this->get($uri)->assertRedirect('/email/verify');
        }

        // A tela inicial do cliente (Meus horários) também espera a confirmação.
        $this->followingRedirects()->get('/')->assertOk()->assertSee('Reenviar o link');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest('web');
    }

    /**
     * Deixa o teste como um navegador numa requisição nova: sem o usuário e a
     * sessão que o teste guarda em memória entre uma chamada e outra, e com o guard
     * padrão de volta para web (uma chamada à API troca para sanctum, e no teste o
     * app é o mesmo de uma chamada para outra). Só o cookie da sessão, quando
     * enviado, traz o login de volta (withCredentials faz o getJson mandar os cookies).
     */
    private function startFreshRequest(): void
    {
        $this->app['auth']->forgetGuards()->shouldUse('web');
        $this->app['session.store']->flush();
        $this->withCredentials();
    }

    public function test_session_from_the_login_screen_opens_the_api_with_the_referer(): void
    {
        $admin = $this->makeAdmin();

        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/');

        $sessionId = session()->getId();

        $this->startFreshRequest();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/user', ['Referer' => 'http://localhost/appointments'])
            ->assertOk()
            ->assertJsonPath('id', $admin->id)
            ->assertJsonPath('role', 'admin');

        // Sem o Referer das telas, o Sanctum não abre a sessão, e a chamada precisa de token.
        $this->startFreshRequest();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    /**
     * Faz o login do admin pela tela e segue para a tela inicial dele (o
     * dashboard), como o navegador. O login guarda na sessão um resumo da senha,
     * que as telas (auth.session) e o Sanctum conferem a cada requisição. Devolve
     * o id da sessão.
     */
    private function logInThroughTheScreen(User $admin): string
    {
        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/');

        $sessionId = session()->getId();

        $this->startFreshRequest();
        $this->withCookie(config('session.cookie'), $sessionId)->get('/')->assertRedirect('/dashboard');

        return $sessionId;
    }

    public function test_password_change_ends_the_screen_sessions_already_open(): void
    {
        $admin = $this->makeAdmin();
        $sessionId = $this->logInThroughTheScreen($admin);

        // A senha muda em outro lugar (outro aparelho, redefinição por e-mail ou o admin).
        $admin->forceFill(['password' => 'senha-nova-123'])->save();

        $this->startFreshRequest();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->get('/appointments')
            ->assertRedirect('/login');
    }

    public function test_password_change_ends_the_session_in_the_api_calls_of_the_screens(): void
    {
        $admin = $this->makeAdmin();
        $sessionId = $this->logInThroughTheScreen($admin);

        $admin->forceFill(['password' => 'senha-nova-123'])->save();

        $this->startFreshRequest();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/user', ['Referer' => 'http://localhost/appointments'])
            ->assertUnauthorized();
    }

    public function test_password_changed_from_the_screens_keeps_that_session(): void
    {
        $admin = $this->makeAdmin();
        $sessionId = $this->logInThroughTheScreen($admin);

        $this->startFreshRequest();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->putJson('/api/user/password', [
                'current_password' => self::PASSWORD,
                'password' => 'senha-nova-123',
                'password_confirmation' => 'senha-nova-123',
            ], ['Referer' => 'http://localhost/account'])
            ->assertNoContent();

        $this->startFreshRequest();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->get('/appointments')
            ->assertOk();
    }

    public function test_api_logout_from_the_screens_ends_the_session(): void
    {
        $admin = $this->makeAdmin();

        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('/');

        $sessionId = session()->getId();

        $this->startFreshRequest();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->postJson('/api/logout', [], ['Referer' => 'http://localhost/appointments'])
            ->assertNoContent();

        $this->startFreshRequest();

        $this->withCookie(config('session.cookie'), $sessionId)
            ->getJson('/api/user', ['Referer' => 'http://localhost/appointments'])
            ->assertUnauthorized();
    }
}
