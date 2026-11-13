<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    public function test_unknown_record_returns_404_without_the_model_name(): void
    {
        $response = $this->getJson('/api/services/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);

        $this->assertStringNotContainsString('Models', $response->getContent());
    }

    public function test_unknown_api_route_returns_404_in_portuguese(): void
    {
        $this->getJson('/api/rota-que-nao-existe')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Rota não encontrada.']);
    }

    public function test_api_answers_in_json_even_without_the_accept_header(): void
    {
        $this->get('/api/rota-que-nao-existe')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Rota não encontrada.']);

        $this->get('/api')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Rota não encontrada.']);
    }

    public function test_wrong_method_returns_405_with_the_allowed_methods(): void
    {
        $response = $this->deleteJson('/api/services')
            ->assertStatus(405)
            ->assertExactJson(['message' => 'Método não permitido para esta rota.']);

        $this->assertStringContainsString('GET', $response->headers->get('Allow'));
        $this->assertStringContainsString('POST', $response->headers->get('Allow'));
    }

    public function test_validation_errors_are_in_portuguese(): void
    {
        $this->postJson('/api/clients', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'O campo nome é obrigatório. (e mais 1 erro)')
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.');
    }

    public function test_unknown_page_outside_the_api_keeps_the_html_error_page(): void
    {
        // A página de erro usa o layout das telas, com o @vite: o teste não depende do npm run build.
        $this->withoutVite();

        $response = $this->get('/pagina-que-nao-existe')->assertNotFound();

        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $response->assertSee('Página não encontrada');
    }

    public function test_expired_session_returns_419_in_portuguese(): void
    {
        // Nos testes o Laravel não confere o CSRF. A rota de teste lança o mesmo erro que ele
        // lançaria com o token da sessão vencido.
        Route::middleware('api')->post('/api/teste-sessao-vencida', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->postJson('/api/teste-sessao-vencida')
            ->assertStatus(419)
            ->assertExactJson(['message' => 'Sua sessão expirou. Recarregue a página.']);
    }

    public function test_unconfirmed_email_message_is_in_portuguese(): void
    {
        // Os cadastros da API usam o verified: a conta de cliente sem o e-mail confirmado não passa.
        $this->actingAsClient(verified: false);

        $this->getJson('/api/services')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Confirme seu e-mail para continuar.']);

        // Sem o cabeçalho Accept também sai JSON, porque a rota é da API.
        $this->get('/api/services')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Confirme seu e-mail para continuar.']);

        $this->actingAsClient();

        $this->getJson('/api/services')->assertOk();
    }
}
