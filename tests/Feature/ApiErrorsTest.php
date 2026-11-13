<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiErrorsTest extends TestCase
{
    use RefreshDatabase;

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
        $response = $this->get('/pagina-que-nao-existe')->assertNotFound();

        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $response->assertSee('Página não encontrada');
    }
}
