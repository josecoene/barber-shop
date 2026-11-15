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
        'scheduled' => ['label' => 'Agendado', 'transitions' => ['confirmed', 'in_progress', 'cancelled', 'no_show'], 'requires_start' => false, 'reschedulable' => true, 'remindable' => true, 'occupies_time' => true, 'active' => true],
        'confirmed' => ['label' => 'Confirmado', 'transitions' => ['in_progress', 'cancelled', 'no_show'], 'requires_start' => false, 'reschedulable' => true, 'remindable' => true, 'occupies_time' => true, 'active' => true],
        'in_progress' => ['label' => 'Em atendimento', 'transitions' => ['completed'], 'requires_start' => true, 'reschedulable' => false, 'remindable' => false, 'occupies_time' => true, 'active' => true],
        'completed' => ['label' => 'Concluído', 'transitions' => [], 'requires_start' => true, 'reschedulable' => false, 'remindable' => false, 'occupies_time' => true, 'active' => false],
        'cancelled' => ['label' => 'Cancelado', 'transitions' => [], 'requires_start' => false, 'reschedulable' => false, 'remindable' => false, 'occupies_time' => false, 'active' => false],
        'no_show' => ['label' => 'Faltou', 'transitions' => [], 'requires_start' => true, 'reschedulable' => false, 'remindable' => false, 'occupies_time' => false, 'active' => false],
    ];

    /**
     * Regras de cada status do pagamento escritas à mão, para conferir o que a
     * tela de Pagamentos recebe do PaymentStatus::catalog().
     */
    private const PAYMENT_STATUS_CATALOG = [
        'pending' => ['label' => 'Pendente', 'transitions' => ['paid'], 'initial' => true],
        'paid' => ['label' => 'Pago', 'transitions' => ['refunded'], 'initial' => true],
        'refunded' => ['label' => 'Estornado', 'transitions' => [], 'initial' => false],
    ];

    /**
     * As formas de pagamento, como o PaymentMethod::catalog() as entrega aos
     * filtros e ao modal de pagamento.
     */
    private const PAYMENT_METHOD_OPTIONS = [
        '<option value="cash">Dinheiro</option>',
        '<option value="pix">Pix</option>',
        '<option value="credit_card">Cartão de crédito</option>',
        '<option value="debit_card">Cartão de débito</option>',
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
            'dashboard' => ['/dashboard'],
            'agenda' => ['/appointments'],
            'agendar' => ['/appointments/create'],
            'pagamentos' => ['/payments'],
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
     * Telas abertas por quem usa cada uma: o barbeiro na Agenda, no Agendar, em
     * Pagamentos e em Clientes (com a fidelidade); o cliente no Agendar dele e
     * em Meus horários.
     */
    public static function pagesByProfile(): array
    {
        return [
            'agenda do barbeiro' => ['makeBarberUser', '/appointments', 'Agenda'],
            'agendar do barbeiro' => ['makeBarberUser', '/appointments/create', 'Agendar'],
            'pagamentos do barbeiro' => ['makeBarberUser', '/payments', 'Pagamentos'],
            'clientes do barbeiro' => ['makeBarberUser', '/clients', 'Clientes'],
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
        $staff = ['appointments.index', 'appointments.create', 'payments.index', 'clients.index'];
        $admin = ['dashboard', 'barbers.index', 'services.index'];
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
            ->assertSeeInOrder([
                'href="'.route('dashboard').'">Dashboard</a>',
                '>Agenda</a>',
                '>Agendar</a>',
                'href="'.route('payments.index').'">Pagamentos</a>',
                '>Clientes</a>',
                '>Barbeiros</a>',
                '>Serviços</a>',
            ], false);
    }

    public function test_barber_menu_follows_the_planned_order(): void
    {
        $this->actingAs($this->makeBarberUser())
            ->get('/account')
            ->assertSeeInOrder(['>Agenda</a>', '>Agendar</a>', '>Pagamentos</a>', '>Clientes</a>'], false)
            ->assertDontSee('>Dashboard</a>', false);
    }

    public function test_home_of_the_admin_is_the_dashboard(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/')
            ->assertRedirect(route('dashboard'));

        // O barbeiro continua indo para a agenda (outro login: a sessão começa limpa).
        $this->flushSession();

        $this->actingAs($this->makeBarberUser())
            ->get('/')
            ->assertRedirect(route('appointments.index'));
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

    /**
     * O detalhe da Agenda diz quantas horas antes sai o lembrete automático, com
     * o mínimo de 1 hora do App\Services\ReminderSender.
     */
    public function test_agenda_gets_the_reminder_window_from_the_config(): void
    {
        $this->actingAs($this->makeBarberUser());

        config(['barberflow.reminders.hours_before' => 48]);
        $this->assertSame('48', $this->dataAttribute($this->get('/appointments')->assertOk()->getContent(), 'data-reminder-hours'));

        config(['barberflow.reminders.hours_before' => 0]);
        $this->assertSame('1', $this->dataAttribute($this->get('/appointments')->assertOk()->getContent(), 'data-reminder-hours'));
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

    /**
     * Meus horários mostra a fidelidade do cadastro ligado à conta, que o
     * pages.js lê no GET /api/clients/{id}/loyalty. Sem cadastro ligado, a tela
     * fica sem essa parte.
     */
    public function test_my_appointments_shows_the_loyalty_of_the_own_client(): void
    {
        $user = $this->makeClientUser();
        $this->actingAs($user);

        $html = $this->get('/my-appointments')
            ->assertOk()
            ->assertSee('id="my-loyalty-title">Fidelidade</h2>', false)
            ->assertSeeInOrder(['data-loyalty', 'my-upcoming-title'], false)
            ->getContent();

        $this->assertSame((string) $user->client->id, $this->dataAttribute($html, 'data-client-id'));

        // O actingAs guarda o mesmo usuário entre os pedidos: a relação já lida sai, como num pedido novo.
        $user->client->user()->dissociate()->save();
        $user->unsetRelation('client');

        $this->get('/my-appointments')
            ->assertOk()
            ->assertDontSee('data-loyalty', false)
            ->assertDontSee('data-client-id', false);
    }

    /**
     * A coluna Fidelidade (ex.: 7/10) vem da própria listagem de clientes, que o
     * admin e o barbeiro abrem.
     */
    public function test_clients_page_has_the_loyalty_column_for_the_staff(): void
    {
        $columns = ['>Nome</th>', '>E-mail</th>', '>Telefone</th>', '>Fidelidade</th>', '>Ações</th>'];

        $this->actingAs($this->makeAdmin())
            ->get('/clients')
            ->assertOk()
            ->assertSeeInOrder($columns, false);

        $this->flushSession();

        $this->actingAs($this->makeBarberUser())
            ->get('/clients')
            ->assertOk()
            ->assertSeeInOrder($columns, false);
    }

    /**
     * O modal de pagamento traz, escondida, a caixa do prêmio de fidelidade: o
     * pages.js só a mostra quando o cliente do atendimento tem prêmio disponível.
     * Vale na tela de Pagamentos e no detalhe da Agenda, para o admin e o barbeiro.
     */
    public function test_payment_modal_offers_the_loyalty_reward(): void
    {
        $pages = [
            ['makeAdmin', '/payments'],
            ['makeAdmin', '/appointments'],
            ['makeBarberUser', '/payments'],
            ['makeBarberUser', '/appointments'],
        ];

        foreach ($pages as [$makeUser, $uri]) {
            $this->flushSession();
            $this->actingAs($this->{$makeUser}());

            $this->get($uri)
                ->assertOk()
                ->assertSee('<div class="mb-3" data-loyalty-reward hidden>', false)
                ->assertSee('name="use_loyalty_reward"', false)
                ->assertSee('Usar o prêmio de fidelidade')
                ->assertSee('data-error-for="use_loyalty_reward"', false)
                // A caixa vem antes do valor, que ela trava.
                ->assertSeeInOrder(['name="use_loyalty_reward"', 'name="amount"'], false);
        }
    }

    public function test_payments_page_gets_the_status_rules_and_the_admin_actions(): void
    {
        $html = $this->actingAs($this->makeAdmin())->get('/payments')->assertOk()->getContent();

        $this->assertSame(self::PAYMENT_STATUS_CATALOG, json_decode($this->dataAttribute($html, 'data-payment-statuses'), true));

        // Corrigir e estornar são só do admin, que também filtra por barbeiro.
        $this->assertSame('true', $this->dataAttribute($html, 'data-can-manage'));
        $this->assertNull($this->dataAttribute($html, 'data-own-barber-id'));

        foreach (['from', 'to', 'method', 'status', 'barber_id'] as $filter) {
            $this->assertStringContainsString('name="'.$filter.'"', $html);
        }

        // As formas vêm do PaymentMethod::catalog(): uma vez no filtro e outra no modal de pagamento.
        foreach (self::PAYMENT_METHOD_OPTIONS as $option) {
            $this->assertSame(2, substr_count($html, $option), $option);
        }

        $this->assertStringContainsString('<option value="refunded">Estornado</option>', $html);
        $this->assertStringContainsString('data-payment-modal', $html);
        $this->assertStringContainsString('name="amount"', $html);
        // Estornar e marcar como pago pedem confirmação no modal da própria tela.
        $this->assertStringContainsString('data-payment-confirm-modal', $html);
    }

    public function test_barber_payments_page_has_no_barber_filter_and_no_admin_actions(): void
    {
        $barberUser = $this->makeBarberUser();

        $html = $this->actingAs($barberUser)->get('/payments')->assertOk()->getContent();

        $this->assertSame('false', $this->dataAttribute($html, 'data-can-manage'));
        $this->assertSame((string) $barberUser->barber->id, $this->dataAttribute($html, 'data-own-barber-id'));
        $this->assertStringNotContainsString('name="barber_id"', $html);
        // O barbeiro também registra pagamento (dos atendimentos da agenda dele).
        $this->assertStringContainsString('data-payment-modal', $html);
    }

    public function test_agenda_detail_registers_payments_with_the_same_modal(): void
    {
        $html = $this->actingAs($this->makeBarberUser())->get('/appointments')->assertOk()->getContent();

        $this->assertStringContainsString('data-payment-modal', $html);
        $this->assertStringContainsString('name="amount"', $html);
        $this->assertStringContainsString('name="notes"', $html);

        foreach (self::PAYMENT_METHOD_OPTIONS as $option) {
            $this->assertSame(1, substr_count($html, $option), $option);
        }
    }

    public function test_dashboard_page_has_the_period_choices_the_cards_and_the_tables(): void
    {
        $this->actingAs($this->makeAdmin())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('<title>Dashboard · BarberFlow</title>', false)
            ->assertSee('data-dashboard', false)
            ->assertSeeInOrder(['Mês atual', 'Mês passado', 'Personalizado'])
            ->assertSee('name="from"', false)
            ->assertSee('name="to"', false)
            ->assertSeeInOrder(['Faturamento', 'Atendimentos', 'Ticket médio', 'Taxa de faltas', 'Ocupação'])
            ->assertSee('Faturamento por dia')
            ->assertSee('Faturamento por forma de pagamento')
            ->assertSee('Atendimentos por status')
            ->assertSee('Top 5 serviços')
            ->assertSee('Por barbeiro');
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
