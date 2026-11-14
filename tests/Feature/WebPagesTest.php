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

    /**
     * Regras de cada status escritas à mão, para conferir o que as telas da
     * agenda recebem do AppointmentStatus::catalog().
     */
    private const STATUS_CATALOG = [
        'scheduled' => ['label' => 'Agendado', 'transitions' => ['confirmed', 'in_progress', 'cancelled', 'no_show'], 'requires_start' => false, 'reschedulable' => true, 'occupies_time' => true, 'active' => true],
        'confirmed' => ['label' => 'Confirmado', 'transitions' => ['in_progress', 'cancelled', 'no_show'], 'requires_start' => false, 'reschedulable' => true, 'occupies_time' => true, 'active' => true],
        'in_progress' => ['label' => 'Em atendimento', 'transitions' => ['completed'], 'requires_start' => true, 'reschedulable' => false, 'occupies_time' => true, 'active' => true],
        'completed' => ['label' => 'Concluído', 'transitions' => [], 'requires_start' => true, 'reschedulable' => false, 'occupies_time' => true, 'active' => false],
        'cancelled' => ['label' => 'Cancelado', 'transitions' => [], 'requires_start' => false, 'reschedulable' => false, 'occupies_time' => false, 'active' => false],
        'no_show' => ['label' => 'Faltou', 'transitions' => [], 'requires_start' => true, 'reschedulable' => false, 'occupies_time' => false, 'active' => false],
    ];

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
     * Telas da agenda abertas por quem usa cada uma: o barbeiro na Agenda e no
     * Agendar; o cliente no Agendar dele e em Meus horários.
     */
    public static function pagesByProfile(): array
    {
        return [
            'agenda do barbeiro' => ['makeBarberUser', '/appointments', 'Agenda'],
            'agendar do barbeiro' => ['makeBarberUser', '/appointments/create', 'Agendar'],
            'agendar do cliente' => ['makeClientUser', '/booking', 'Agendar'],
            'meus horários do cliente' => ['makeClientUser', '/my-appointments', 'Meus horários'],
        ];
    }

    #[DataProvider('pagesByProfile')]
    public function test_page_responds_ok_for_the_profile_that_uses_it(string $makeUser, string $uri, string $title): void
    {
        $this->actingAs($this->{$makeUser}())
            ->get($uri)
            ->assertOk()
            ->assertSee("<title>{$title} · BarberFlow</title>", false);
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
        $client = ['booking.create', 'my-appointments.index'];

        return [
            'admin' => ['makeAdmin', [...$staff, ...$admin], $client],
            'barbeiro' => ['makeBarberUser', $staff, [...$admin, ...$client]],
            'cliente' => ['makeClientUser', [...$client, 'account.show'], [...$staff, ...$admin]],
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

    public function test_client_menu_follows_the_planned_order(): void
    {
        $this->actingAs($this->makeClientUser())
            ->get('/account')
            ->assertSeeInOrder([
                'href="'.route('booking.create').'">Agendar</a>',
                'href="'.route('my-appointments.index').'">Meus horários</a>',
                'href="'.route('account.show').'">Minha conta</a>',
            ], false);
    }

    public function test_client_sees_my_account_only_once(): void
    {
        $response = $this->actingAs($this->makeClientUser())->get('/account')->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'href="'.route('account.show').'"'));
    }

    public function test_layout_tells_the_screens_the_timezone_of_the_barbershop(): void
    {
        config(['app.timezone' => 'America/Sao_Paulo']);

        $this->actingAs($this->makeAdmin())
            ->get('/appointments')
            ->assertSee('<meta name="app-timezone" content="America/Sao_Paulo">', false);
    }

    /**
     * As telas da agenda funcionam com a API: some o aviso de "só visual" da
     * versão anterior.
     */
    public function test_schedule_pages_no_longer_say_they_are_only_visual(): void
    {
        $this->actingAs($this->makeAdmin());

        foreach (['/appointments', '/appointments/create'] as $uri) {
            $this->get($uri)
                ->assertOk()
                ->assertDontSee('só visual')
                ->assertDontSee('nada é salvo');
        }
    }

    /**
     * Valor de um data-attribute da tela, já sem o escape do HTML.
     */
    private function dataAttribute(string $html, string $name): ?string
    {
        if (preg_match('/\s'.preg_quote($name, '/').'="([^"]*)"/', $html, $match) !== 1) {
            return null;
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    public function test_agenda_gets_the_business_hours_and_the_status_rules_from_the_server(): void
    {
        config(['barberflow.business' => ['opening' => '08:00', 'closing' => '18:30', 'slot_minutes' => 20, 'days' => [1, 2, 3]]]);

        $html = $this->actingAs($this->makeAdmin())->get('/appointments')->assertOk()->getContent();

        $this->assertSame('08:00', $this->dataAttribute($html, 'data-opening'));
        $this->assertSame('18:30', $this->dataAttribute($html, 'data-closing'));
        $this->assertSame('20', $this->dataAttribute($html, 'data-slot-minutes'));
        $this->assertSame(self::STATUS_CATALOG, json_decode($this->dataAttribute($html, 'data-statuses'), true));
        $this->assertSame(route('appointments.create'), $this->dataAttribute($html, 'data-create-url'));

        // O admin vê a coluna de todos os barbeiros.
        $this->assertNull($this->dataAttribute($html, 'data-own-barber-id'));
    }

    public function test_barber_sees_the_own_agenda_and_books_only_in_it(): void
    {
        $barberUser = $this->makeBarberUser();
        $barber = $barberUser->barber;
        $this->actingAs($barberUser);

        $agenda = $this->get('/appointments')->assertOk()->getContent();

        $this->assertSame((string) $barber->id, $this->dataAttribute($agenda, 'data-own-barber-id'));
        $this->assertSame($barber->name, $this->dataAttribute($agenda, 'data-own-barber-name'));

        // No Agendar, o campo de barbeiro já vem com ele e desabilitado.
        $this->get('/appointments/create')
            ->assertOk()
            ->assertSee('data-own-barber-id="'.$barber->id.'"', false)
            ->assertSee('<option value="'.$barber->id.'" selected>'.$barber->name.'</option>', false)
            ->assertSee('Você agenda na sua própria agenda.');
    }

    public function test_staff_booking_form_has_the_client_and_the_notes(): void
    {
        config(['barberflow.business.days' => [1, 2, 3, 4, 5], 'barberflow.booking_days_ahead' => 30]);

        $html = $this->actingAs($this->makeAdmin())->get('/appointments/create')->assertOk()->getContent();

        $this->assertSame('staff', $this->dataAttribute($html, 'data-mode'));
        $this->assertSame('1,2,3,4,5', $this->dataAttribute($html, 'data-business-days'));
        $this->assertSame('30', $this->dataAttribute($html, 'data-booking-days-ahead'));
        $this->assertSame(route('appointments.index'), $this->dataAttribute($html, 'data-success-url'));
        $this->assertStringContainsString('name="client_id"', $html);
        $this->assertStringContainsString('name="notes"', $html);
        $this->assertStringContainsString('data-error-group="start_time"', $html);
        $this->assertNull($this->dataAttribute($html, 'data-own-barber-id'));
    }

    public function test_client_booking_form_has_no_client_field(): void
    {
        $html = $this->actingAs($this->makeClientUser())->get('/booking')->assertOk()->getContent();

        $this->assertSame('client', $this->dataAttribute($html, 'data-mode'));
        $this->assertSame('1,2,3,4,5,6', $this->dataAttribute($html, 'data-business-days'));
        $this->assertSame('60', $this->dataAttribute($html, 'data-booking-days-ahead'));
        $this->assertSame(route('my-appointments.index'), $this->dataAttribute($html, 'data-success-url'));
        $this->assertStringNotContainsString('name="client_id"', $html);
        $this->assertStringNotContainsString('name="notes"', $html);
        $this->assertStringContainsString('name="barber_id"', $html);
        $this->assertStringContainsString('name="service_id"', $html);
        $this->assertStringContainsString('name="date"', $html);
    }

    public function test_my_appointments_gets_the_cancel_window_from_the_config(): void
    {
        config(['barberflow.client_cancel_min_hours' => 5]);
        $this->actingAs($this->makeClientUser());

        $html = $this->get('/my-appointments')
            ->assertOk()
            ->assertSee('até 5 horas antes do horário')
            ->assertSee('data-cancel-modal', false)
            ->getContent();

        $this->assertSame('5', $this->dataAttribute($html, 'data-cancel-min-hours'));
        $this->assertSame(self::STATUS_CATALOG, json_decode($this->dataAttribute($html, 'data-statuses'), true));
        $this->assertSame(route('booking.create'), $this->dataAttribute($html, 'data-booking-url'));

        config(['barberflow.client_cancel_min_hours' => 1]);

        $this->get('/my-appointments')->assertSee('até 1 hora antes do horário');
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

        // As telas do barbeiro e do cliente, cada uma com o seu login.
        foreach (self::pagesByProfile() as [$makeUser, $uri]) {
            $this->flushSession();
            $this->actingAs($this->{$makeUser}());

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
