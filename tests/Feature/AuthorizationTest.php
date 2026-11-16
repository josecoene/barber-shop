<?php

namespace Tests\Feature;

use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quem pode o quê na API: 401 sem login em toda rota protegida e 403 (ou 404,
 * quando o registro fica escondido) para cada perfil sem permissão.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN = ['message' => 'Você não tem permissão para esta ação.'];

    /**
     * Rotas abertas de propósito: login, cadastro, "esqueci a senha" e health.
     */
    private const OPEN_API_ROUTES = ['api.login', 'api.register', 'api.password.email', 'api.password.store', 'api.health'];

    /**
     * Rotas da própria conta: pedem login, mas não o e-mail confirmado (quem ainda não
     * confirmou precisa poder reenviar o link, trocar a senha e sair).
     */
    private const ACCOUNT_API_ROUTES = ['api.user', 'api.user.password', 'api.logout', 'api.verification.send'];

    private const UNVERIFIED = ['message' => 'Confirme seu e-mail para continuar.'];

    public static function protectedApiRoutes(): array
    {
        return [
            'listar serviços' => ['GET', '/api/services'],
            'criar serviço' => ['POST', '/api/services'],
            'ver serviço' => ['GET', '/api/services/1'],
            'alterar serviço' => ['PUT', '/api/services/1'],
            'excluir serviço' => ['DELETE', '/api/services/1'],
            'listar barbeiros' => ['GET', '/api/barbers'],
            'criar barbeiro' => ['POST', '/api/barbers'],
            'ver barbeiro' => ['GET', '/api/barbers/1'],
            'alterar barbeiro' => ['PUT', '/api/barbers/1'],
            'excluir barbeiro' => ['DELETE', '/api/barbers/1'],
            'listar clientes' => ['GET', '/api/clients'],
            'criar cliente' => ['POST', '/api/clients'],
            'ver cliente' => ['GET', '/api/clients/1'],
            'alterar cliente' => ['PUT', '/api/clients/1'],
            'excluir cliente' => ['DELETE', '/api/clients/1'],
            'fidelidade do cliente' => ['GET', '/api/clients/1/loyalty'],
            'listar agendamentos' => ['GET', '/api/appointments'],
            'criar agendamento' => ['POST', '/api/appointments'],
            'ver agendamento' => ['GET', '/api/appointments/1'],
            'remarcar agendamento' => ['PUT', '/api/appointments/1'],
            'mudar o status do agendamento' => ['PATCH', '/api/appointments/1/status'],
            'enviar lembrete agora' => ['POST', '/api/appointments/1/reminder'],
            'horários livres do barbeiro' => ['GET', '/api/barbers/1/available-slots?date=2026-11-16&service_id=1'],
            'listar pagamentos' => ['GET', '/api/payments'],
            'registrar pagamento' => ['POST', '/api/payments'],
            'ver pagamento' => ['GET', '/api/payments/1'],
            'corrigir pagamento' => ['PUT', '/api/payments/1'],
            'mudar o status do pagamento' => ['PATCH', '/api/payments/1/status'],
            'dashboard' => ['GET', '/api/dashboard'],
            'usuário logado' => ['GET', '/api/user'],
            'trocar a senha' => ['PUT', '/api/user/password'],
            'sair' => ['POST', '/api/logout'],
            'reenviar a confirmação' => ['POST', '/api/email/verification-notification'],
        ];
    }

    #[DataProvider('protectedApiRoutes')]
    public function test_protected_route_without_login_returns_401(string $method, string $uri): void
    {
        // Os ids existem: o 401 vem antes de qualquer outra resposta.
        Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 40]);
        Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);

        $this->json($method, $uri)
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Não autenticado.']);
    }

    public function test_api_answers_401_in_json_even_without_the_accept_header(): void
    {
        $this->get('/api/services')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Não autenticado.']);
    }

    public function test_every_api_route_outside_the_open_list_requires_login(): void
    {
        $unprotected = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'api.'))
            ->reject(fn (RouteDefinition $route) => in_array($route->getName(), self::OPEN_API_ROUTES, true))
            ->reject(fn (RouteDefinition $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->map(fn (RouteDefinition $route) => $route->getName())
            ->values()
            ->all();

        $this->assertSame([], $unprotected);
    }

    /**
     * Cadastros (e o que vier depois, como a agenda) só com o e-mail confirmado. Ficam de
     * fora só as rotas abertas e as da própria conta.
     */
    public function test_every_api_route_outside_the_account_requires_the_confirmed_email(): void
    {
        $unverified = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'api.'))
            ->reject(fn (RouteDefinition $route) => in_array($route->getName(), [...self::OPEN_API_ROUTES, ...self::ACCOUNT_API_ROUTES], true))
            ->reject(fn (RouteDefinition $route) => in_array('verified', $route->gatherMiddleware(), true))
            ->map(fn (RouteDefinition $route) => $route->getName())
            ->values()
            ->all();

        $this->assertSame([], $unverified);
    }

    public function test_client_without_the_confirmed_email_only_reaches_the_account(): void
    {
        $own = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 40]);
        Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $this->actingAsClient($own, verified: false);

        // Uma conta criada com o e-mail de outra pessoa não vê nada dos cadastros antes de provar que o e-mail é dela.
        $this->getJson('/api/services')->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->getJson('/api/barbers')->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->getJson("/api/clients/{$own->id}")->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->patchJson("/api/clients/{$own->id}", ['name' => 'Outro nome'])->assertForbidden()->assertExactJson(self::UNVERIFIED);

        $this->assertSame('Ana', $own->fresh()->name);

        // As rotas da própria conta (como o /api/user) continuam abertas para ela.
        $this->getJson('/api/user')->assertOk()->assertJsonPath('email_verified_at', null);
    }

    public function test_health_stays_public(): void
    {
        $this->getJson('/api/health')->assertOk();
    }

    public function test_user_without_role_gets_403_on_every_resource(): void
    {
        // Com o e-mail confirmado, para o 403 vir da falta de perfil (sem confirmar, viria do verified).
        $user = User::create(['name' => 'Sem Perfil', 'email' => 'sem.perfil@barberflow.test', 'password' => self::PASSWORD]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        foreach (['/api/services', '/api/barbers', '/api/clients', '/api/appointments', '/api/payments', '/api/dashboard'] as $uri) {
            $this->getJson($uri)->assertForbidden()->assertExactJson(self::FORBIDDEN);
        }
    }

    public function test_barber_only_views_services(): void
    {
        $service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 40]);
        $inactive = Service::create(['name' => 'Pigmentação', 'duration' => 40, 'price' => 50, 'status' => 'inactive']);
        $this->actingAsBarber();

        $this->getJson('/api/services')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/services/{$inactive->id}")->assertOk();

        $this->postJson('/api/services', ['name' => 'Barba', 'duration' => 20, 'price' => 25])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);
        $this->putJson("/api/services/{$service->id}", ['price' => 1])->assertForbidden();
        $this->deleteJson("/api/services/{$service->id}")->assertForbidden();

        $this->assertSame(2, Service::count());
        $this->assertEquals(40, (float) $service->fresh()->price);
    }

    public function test_barber_views_barbers_but_does_not_change_them(): void
    {
        $other = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test', 'phone' => '67988880000']);
        $this->actingAsBarber();

        $this->getJson("/api/barbers/{$other->id}")
            ->assertOk()
            ->assertJsonPath('email', 'pedro@barberflow.test')
            ->assertJsonPath('phone', '67988880000');

        $this->postJson('/api/barbers', ['name' => 'Novo', 'email' => 'novo@barberflow.test'])->assertForbidden();
        $this->putJson("/api/barbers/{$other->id}", ['name' => 'Outro nome'])->assertForbidden();
        $this->putJson("/api/barbers/{$other->id}", ['password' => 'senha-do-pedro'])->assertForbidden();
        $this->deleteJson("/api/barbers/{$other->id}")->assertForbidden();

        $this->assertSame('Pedro', $other->fresh()->name);
        $this->assertNull($other->fresh()->user_id);
    }

    public function test_barber_views_creates_and_edits_clients_but_does_not_delete(): void
    {
        $client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->actingAsBarber();

        $this->getJson('/api/clients')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/clients/{$client->id}")->assertOk();
        $this->postJson('/api/clients', ['name' => 'Bruno', 'email' => 'bruno@barberflow.test'])->assertCreated();
        $this->patchJson("/api/clients/{$client->id}", ['email' => 'ana.nova@barberflow.test'])->assertOk();

        $this->deleteJson("/api/clients/{$client->id}")
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);

        $this->assertNotSoftDeleted($client);
    }

    public function test_client_does_not_list_clients(): void
    {
        Client::create(['name' => 'Outra pessoa', 'email' => 'outra@barberflow.test']);
        $this->actingAsClient();

        $this->getJson('/api/clients')
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);
    }

    public function test_client_sees_and_edits_only_the_own_record(): void
    {
        $own = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $other = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test', 'phone' => '67911112222']);
        $this->actingAsClient($own);

        $this->getJson("/api/clients/{$own->id}")
            ->assertOk()
            ->assertJsonPath('email', 'ana@barberflow.test');

        $this->patchJson("/api/clients/{$own->id}", ['name' => 'Ana Souza', 'phone' => '67933334444'])
            ->assertOk()
            ->assertJsonPath('name', 'Ana Souza')
            ->assertJsonPath('phone', '67933334444')
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('user');

        // O e-mail do cadastro fica com a barbearia.
        $this->patchJson("/api/clients/{$own->id}", ['email' => 'ana.outra@barberflow.test'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'O campo e-mail não é permitido.');

        $this->getJson("/api/clients/{$other->id}")->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/clients/{$other->id}", ['name' => 'Invasor'])->assertForbidden();
        $this->postJson('/api/clients', ['name' => 'Carla', 'email' => 'carla@barberflow.test'])->assertForbidden();
        $this->deleteJson("/api/clients/{$own->id}")->assertForbidden();

        $this->assertSame('ana@barberflow.test', $own->fresh()->email);
        $this->assertSame('Bruno', $other->fresh()->name);
        $this->assertSame(2, Client::count());
    }

    public static function emailsTheClientCannotSend(): array
    {
        return [
            'e-mail novo' => ['ana.outra@barberflow.test'],
            // O ConvertEmptyStringsToNull troca o texto vazio por null antes da validação.
            'texto vazio' => [''],
            'null' => [null],
        ];
    }

    /**
     * Nem vazio: antes, a regra prohibited deixava passar e-mail vazio ou null, que chegava ao
     * banco como null e dava erro 500 (a coluna é NOT NULL).
     */
    #[DataProvider('emailsTheClientCannotSend')]
    public function test_client_cannot_send_the_email_of_the_own_record(?string $email): void
    {
        $own = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->actingAsClient($own);

        $this->patchJson("/api/clients/{$own->id}", ['name' => 'Ana Souza', 'email' => $email])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'O campo e-mail não é permitido.');

        $this->assertSame('ana@barberflow.test', $own->fresh()->email);
        $this->assertSame('Ana', $own->fresh()->name);
    }

    public function test_client_sees_only_the_id_and_the_name_of_barbers(): void
    {
        $barber = Barber::create(['name' => 'João', 'email' => 'joao@navalha.test', 'phone' => '67988880000']);
        $this->makeBarberUser($barber);
        $this->actingAsClient();

        $this->getJson('/api/barbers')
            ->assertOk()
            ->assertJsonPath('data', [['id' => $barber->id, 'name' => 'João']]);

        $this->getJson("/api/barbers/{$barber->id}")
            ->assertOk()
            ->assertExactJson(['id' => $barber->id, 'name' => 'João']);

        // A busca do cliente olha só o nome: buscar pelo e-mail não acha ninguém.
        $this->getJson('/api/barbers?search=navalha')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/barbers?search=jo')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/barbers', ['name' => 'Novo', 'email' => 'novo@barberflow.test'])->assertForbidden();
        $this->putJson("/api/barbers/{$barber->id}", ['name' => 'Outro'])->assertForbidden();
        $this->deleteJson("/api/barbers/{$barber->id}")->assertForbidden();
    }

    public function test_client_does_not_see_inactive_services(): void
    {
        $active = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 40]);
        $inactive = Service::create(['name' => 'Pigmentação', 'duration' => 40, 'price' => 50, 'status' => 'inactive']);
        $this->actingAsClient();

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        // O filtro de status não abre os inativos para o cliente.
        $this->getJson('/api/services?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);

        $this->getJson("/api/services/{$active->id}")->assertOk();

        // Para o cliente, o serviço inativo é como um id que não existe.
        $this->getJson("/api/services/{$inactive->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);

        $this->postJson('/api/services', ['name' => 'Barba', 'duration' => 20, 'price' => 25])->assertForbidden();
        $this->putJson("/api/services/{$active->id}", ['price' => 1])->assertForbidden();
        $this->deleteJson("/api/services/{$active->id}")->assertForbidden();
    }

    public function test_admin_can_do_everything(): void
    {
        $client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $service = Service::create(['name' => 'Pigmentação', 'duration' => 40, 'price' => 50, 'status' => 'inactive']);
        $this->actingAsAdmin();

        $this->getJson("/api/services/{$service->id}")->assertOk();
        $this->getJson("/api/barbers/{$barber->id}")->assertOk()->assertJsonPath('has_login', false);
        $this->deleteJson("/api/clients/{$client->id}")->assertNoContent();
        $this->deleteJson("/api/barbers/{$barber->id}")->assertNoContent();
        $this->deleteJson("/api/services/{$service->id}")->assertNoContent();
    }
}
