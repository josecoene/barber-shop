<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cada tela responde, o layout, o menu por perfil, as páginas de erro e os nomes
 * de rota. Quem pode abrir cada tela e o login ficam no WebAuthTest.
 */
class WebPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Telas que pedem login, abertas como admin (que vê todas).
     */
    public static function pages(): array
    {
        return [
            'agenda' => ['/appointments'],
            'agendar' => ['/appointments/create'],
            'clientes' => ['/clients'],
            'barbeiros' => ['/barbers'],
            'serviços' => ['/services'],
            'minha conta' => ['/account'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_responds_ok(string $uri): void
    {
        $this->actingAs($this->makeAdmin())
            ->get($uri)
            ->assertOk();
    }

    /**
     * Telas de visitante: login, cadastro, esqueci e redefinir a senha.
     */
    public static function guestPages(): array
    {
        return [
            'login' => ['/login', 'Entrar'],
            'cadastro' => ['/register', 'Criar conta'],
            'esqueci a senha' => ['/forgot-password', 'Esqueci minha senha'],
            'redefinir a senha' => ['/reset-password/token-de-teste?email=cliente%40barberflow.test', 'Redefinir senha'],
        ];
    }

    #[DataProvider('guestPages')]
    public function test_guest_page_responds_ok(string $uri, string $title): void
    {
        $this->get($uri)
            ->assertOk()
            ->assertSee("<title>{$title} · BarberFlow</title>", false)
            ->assertSee('name="_token"', false);
    }

    public function test_reset_page_brings_the_token_and_the_email_of_the_link(): void
    {
        $this->get('/reset-password/token-de-teste?email=cliente%40barberflow.test')
            ->assertOk()
            ->assertSee('name="token" value="token-de-teste"', false)
            ->assertSee('value="cliente@barberflow.test"', false);
    }

    public function test_reset_page_ignores_an_email_that_is_not_text(): void
    {
        $this->get('/reset-password/token-de-teste?email[]=cliente%40barberflow.test')
            ->assertOk()
            ->assertDontSee('cliente@barberflow.test');
    }

    public function test_email_confirmation_notice_responds_ok(): void
    {
        $client = $this->makeClientUser(verified: false);

        $this->actingAs($client)
            ->get('/email/verify')
            ->assertOk()
            ->assertSee($client->email)
            ->assertSee('action="'.route('verification.send').'"', false)
            ->assertSee('Reenviar o link');
    }

    public function test_login_page_posts_to_the_server_with_remember_me_and_the_account_links(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('method="POST" action="'.route('login.store').'"', false)
            ->assertSee('name="remember"', false)
            ->assertSee('Lembrar de mim')
            ->assertSee('href="'.route('register').'"', false)
            ->assertSee('href="'.route('password.request').'"', false)
            ->assertDontSee('Continuar sem login')
            ->assertDontSee('Sua sessão expirou');
    }

    public function test_login_page_explains_the_expired_session_and_shows_the_status(): void
    {
        $this->get('/login?expirou=1')
            ->assertOk()
            ->assertSee('Sua sessão expirou. Entre de novo para continuar.');

        $this->withSession(['status' => 'Sua senha foi redefinida.'])
            ->get('/login')
            ->assertSee('Sua senha foi redefinida.');
    }

    public function test_login_page_keeps_the_email_and_shows_the_error(): void
    {
        $this->from('/login')
            ->followingRedirects()
            ->post('/login', ['email' => 'ninguem@barberflow.test', 'password' => 'senha-errada'])
            ->assertOk()
            ->assertSee('value="ninguem@barberflow.test"', false)
            ->assertSee('E-mail ou senha incorretos.')
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_layout_has_the_csrf_token_the_user_and_the_logout_form(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get('/appointments')
            ->assertOk()
            ->assertSee('<meta name="csrf-token" content="', false)
            ->assertSee($admin->name)
            ->assertSee('Administrador')
            ->assertSee('<form method="POST" action="'.route('logout').'"', false)
            ->assertSee('href="'.route('account.show').'"', false)
            // O Sair é um formulário POST, não mais um link para o login.
            ->assertDontSee('href="'.route('login').'"', false);
    }

    /**
     * Links do menu (rota => texto) que cada perfil vê. Abre a tela Minha conta,
     * que todos os perfis acessam e que não tem outros links para essas telas.
     */
    public static function menus(): array
    {
        $staff = ['appointments.index', 'appointments.create', 'clients.index'];
        $admin = ['barbers.index', 'services.index'];

        return [
            'admin' => ['makeAdmin', [...$staff, ...$admin], []],
            'barbeiro' => ['makeBarberUser', $staff, $admin],
            'cliente' => ['makeClientUser', ['account.show'], [...$staff, ...$admin]],
        ];
    }

    #[DataProvider('menus')]
    public function test_menu_shows_only_the_pages_of_the_profile(string $makeUser, array $visible, array $hidden): void
    {
        /** @var User $user */
        $user = $this->{$makeUser}();

        $response = $this->actingAs($user)->get('/account')->assertOk();

        foreach ($visible as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }

        foreach ($hidden as $route) {
            $response->assertDontSee('href="'.route($route).'"', false);
        }
    }

    public function test_admin_menu_follows_the_planned_order(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/account')
            ->assertSeeInOrder(['>Agenda</a>', '>Agendar</a>', '>Clientes</a>', '>Barbeiros</a>', '>Serviços</a>'], false);
    }

    public function test_client_sees_my_account_only_once(): void
    {
        $response = $this->actingAs($this->makeClientUser())->get('/account')->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('account.show').'"'));
    }

    public function test_menu_links_point_to_the_pages_and_not_to_the_api(): void
    {
        $response = $this->actingAs($this->makeAdmin())
            ->get('/appointments')
            ->assertOk();

        foreach (['barbers', 'clients', 'services'] as $page) {
            $url = route("{$page}.index");

            $this->assertStringEndsWith("/{$page}", $url);
            $this->assertStringNotContainsString('/api/', $url);
            $response->assertSee('href="'.$url.'"', false);
        }
    }

    public function test_barbers_page_has_the_access_column_and_the_password_field(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/barbers')
            ->assertOk()
            ->assertSeeInOrder(['>Nome</th>', '>E-mail</th>', '>Telefone</th>', '>Acesso</th>'], false)
            ->assertSee('name="password"', false)
            ->assertSee('autocomplete="new-password"', false)
            ->assertSee('Preencha para criar ou redefinir o acesso do barbeiro');
    }

    public function test_only_the_admin_gets_the_delete_button_on_the_clients_page(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/clients')
            ->assertSee('data-can-delete="true"', false);

        // Outro usuário é um login novo: a sessão começa limpa (o auth.session derrubaria a do admin).
        $this->flushSession();

        $this->actingAs($this->makeBarberUser())
            ->get('/clients')
            ->assertOk()
            ->assertSee('data-can-delete="false"', false);
    }

    public function test_account_page_shows_the_user_and_the_password_form(): void
    {
        $client = $this->makeClientUser();

        $this->actingAs($client)
            ->get('/account')
            ->assertOk()
            ->assertSee($client->email)
            ->assertSee('Cliente')
            ->assertSee('Confirmado')
            ->assertSee('data-password-form', false)
            ->assertSee('name="current_password"', false)
            ->assertSee('name="password_confirmation"', false);
    }

    /**
     * Páginas de erro em português, com o título de cada código.
     */
    public static function errorPages(): array
    {
        return [
            'erro 403' => [403, 'Acesso negado'],
            'erro 404' => [404, 'Página não encontrada'],
            'erro 419' => [419, 'Sessão expirada'],
            'erro 429' => [429, 'Muitas tentativas'],
            'erro 500' => [500, 'Erro no servidor'],
            'erro 503' => [503, 'Sistema fora do ar'],
        ];
    }

    #[DataProvider('errorPages')]
    public function test_error_pages_are_in_portuguese(int $status, string $title): void
    {
        Route::get("/teste-erro-{$status}", fn () => abort($status));

        $this->get("/teste-erro-{$status}")
            ->assertStatus($status)
            ->assertSee("<title>{$title} · BarberFlow</title>", false)
            ->assertSee("Erro {$status}");
    }

    public function test_too_many_requests_page_says_when_to_try_again(): void
    {
        Route::get('/teste-erro-429-com-prazo', fn () => abort(429, headers: ['Retry-After' => 42]));

        $this->get('/teste-erro-429-com-prazo')
            ->assertStatus(429)
            ->assertSee('Tente de novo em 42 segundos.');
    }

    public function test_client_gets_the_403_page_in_portuguese(): void
    {
        $this->actingAs($this->makeClientUser())
            ->get('/barbers')
            ->assertForbidden()
            ->assertSee('Você não tem permissão para abrir esta página.');
    }

    /**
     * A CSP (style-src 'self') bloqueia estilo inline: nenhuma tela usa style="".
     */
    public function test_pages_have_no_inline_style(): void
    {
        $pages = [
            ...array_column(self::guestPages(), 0),
            '/teste-erro-404',
        ];

        foreach ($pages as $uri) {
            $this->assertStringNotContainsString('style=', $this->get($uri)->getContent(), $uri);
        }

        $this->actingAs($this->makeAdmin());

        foreach (array_column(self::pages(), 0) as $uri) {
            $this->assertStringNotContainsString('style=', $this->get($uri)->assertOk()->getContent(), $uri);
        }
    }

    public function test_route_names_are_unique(): void
    {
        $duplicates = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RouteDefinition $route) => $route->getName())
            ->filter()
            ->duplicates()
            ->values()
            ->all();

        $this->assertSame([], $duplicates);
    }
}
