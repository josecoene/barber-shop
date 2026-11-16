<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O CRUD é testado como admin, que pode tudo. As regras dos outros perfis
     * ficam no AuthorizationTest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Corte de cabelo',
            'duration' => 30,
            'price' => 40.00,
            'status' => 'active',
        ], $overrides);
    }

    public function test_lists_services_paginated_and_ordered_by_name(): void
    {
        Service::create($this->payload(['name' => 'Corte']));
        Service::create($this->payload(['name' => 'Barba']));

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Barba')
            ->assertJsonPath('data.1.name', 'Corte')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_page_comes_as_data_links_and_meta(): void
    {
        Service::create($this->payload());

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'duration', 'price', 'status']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
            ])
            ->assertJsonMissingPaths(['current_page', 'last_page', 'total', 'next_page_url']);
    }

    public function test_per_page_changes_the_page_size(): void
    {
        Service::create($this->payload(['name' => 'Corte']));
        Service::create($this->payload(['name' => 'Barba']));
        Service::create($this->payload(['name' => 'Acabamento']));

        $response = $this->getJson('/api/services?per_page=2&page=2&status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Corte')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('links.next', null);

        $this->assertStringContainsString('per_page=2', $response->json('links.prev'));
        $this->assertStringContainsString('status=active', $response->json('links.prev'));
    }

    public static function invalidPage(): array
    {
        return [
            'zero' => ['0'],
            'negativa' => ['-5'],
            'texto' => ['abc'],
            'acima de 100000' => ['100001'],
            'grande demais para a conta do offset' => ['999999999999999999'],
        ];
    }

    #[DataProvider('invalidPage')]
    public function test_rejects_invalid_page(string $page): void
    {
        // Com pelo menos um registro, a página enorme chegava à conta do offset e virava erro 500.
        Service::create($this->payload());

        $this->getJson("/api/services?page={$page}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page'])
            ->assertJsonPath('errors.page.0', fn (string $message) => str_starts_with($message, 'O campo página'));
    }

    public function test_accepts_the_last_allowed_page(): void
    {
        Service::create($this->payload());

        $this->getJson('/api/services?page=100000&per_page=100')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 100000)
            ->assertJsonPath('meta.total', 1);
    }

    public static function invalidPerPage(): array
    {
        return [
            'zero' => ['0'],
            'acima de 100' => ['101'],
            'texto' => ['abc'],
        ];
    }

    #[DataProvider('invalidPerPage')]
    public function test_rejects_invalid_per_page(string $perPage): void
    {
        $this->getJson("/api/services?per_page={$perPage}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page'])
            ->assertJsonPath('errors.per_page.0', fn (string $message) => str_starts_with($message, 'O campo itens por página'));
    }

    public function test_searches_services_by_name(): void
    {
        Service::create($this->payload(['name' => 'Corte de cabelo']));
        Service::create($this->payload(['name' => 'Barba']));
        Service::create($this->payload(['name' => 'Corte + Barba']));

        $this->getJson('/api/services?search=barba')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Barba')
            ->assertJsonPath('data.1.name', 'Corte + Barba');
    }

    public function test_filters_services_by_status(): void
    {
        Service::create($this->payload(['name' => 'Corte']));
        Service::create($this->payload(['name' => 'Pigmentação', 'status' => 'inactive']));

        $this->getJson('/api/services?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Corte');

        $this->getJson('/api/services?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pigmentação');
    }

    public function test_rejects_invalid_status_filter_and_long_search(): void
    {
        $this->getJson('/api/services?status=foo&search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'search']);
    }

    public function test_creates_a_service(): void
    {
        $this->postJson('/api/services', $this->payload())
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Corte de cabelo', 'duration' => 30]);

        $this->assertDatabaseHas('services', [
            'name' => 'Corte de cabelo',
            'duration' => 30,
            'status' => 'active',
        ]);
    }

    public function test_status_is_optional_and_defaults_to_active(): void
    {
        $payload = $this->payload();
        unset($payload['status']);

        $this->postJson('/api/services', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('services', ['name' => 'Corte de cabelo', 'status' => 'active']);
    }

    public function test_price_is_always_a_string_with_two_decimals_and_duration_an_integer(): void
    {
        $created = $this->postJson('/api/services', $this->payload(['duration' => '35', 'price' => 35]))
            ->assertCreated()
            ->assertJsonPath('duration', 35)
            ->assertJsonPath('price', '35.00');

        $this->getJson("/api/services/{$created->json('id')}")
            ->assertOk()
            ->assertJsonPath('duration', 35)
            ->assertJsonPath('price', '35.00');

        $this->getJson('/api/services')
            ->assertJsonPath('data.0.price', '35.00');
    }

    public function test_rejects_missing_required_fields(): void
    {
        $this->postJson('/api/services', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'duration', 'price'])
            ->assertJsonMissingValidationErrors(['status'])
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.')
            ->assertJsonPath('errors.duration.0', 'O campo duração é obrigatório.')
            ->assertJsonPath('errors.price.0', 'O campo preço é obrigatório.');
    }

    public function test_rejects_invalid_status(): void
    {
        $this->postJson('/api/services', $this->payload(['status' => 'foo']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public static function invalidFields(): array
    {
        return [
            'duração zero' => ['duration', 0],
            'duração abaixo de 5 minutos' => ['duration', 4],
            'duração acima de 8 horas' => ['duration', 481],
            'duração quebrada' => ['duration', 30.5],
            'preço negativo' => ['price', -1],
            'preço acima do máximo' => ['price', 1000000],
            'preço com centavo acima do máximo' => ['price', 999999.991],
            'nome com 256 caracteres' => ['name', str_repeat('a', 256)],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_values_outside_the_limits(string $field, mixed $value): void
    {
        $this->postJson('/api/services', $this->payload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_accepts_values_at_the_limits(): void
    {
        $this->postJson('/api/services', $this->payload(['name' => str_repeat('a', 255), 'duration' => 5, 'price' => 0]))
            ->assertCreated()
            ->assertJsonPath('price', '0.00');

        $this->postJson('/api/services', $this->payload(['duration' => 480, 'price' => 999999.99]))
            ->assertCreated()
            ->assertJsonPath('price', '999999.99');
    }

    public function test_limit_messages_are_in_portuguese(): void
    {
        $this->postJson('/api/services', $this->payload(['duration' => 481, 'price' => 1000000]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.duration.0', 'O campo duração não pode ser maior que 480.')
            ->assertJsonPath('errors.price.0', 'O campo preço não pode ser maior que R$ 999.999,99.');
    }

    public function test_shows_a_service(): void
    {
        $service = Service::create($this->payload());

        $this->getJson("/api/services/{$service->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $service->id, 'name' => 'Corte de cabelo']);
    }

    public function test_updates_a_service_partially(): void
    {
        $service = Service::create($this->payload());

        $this->patchJson("/api/services/{$service->id}", ['price' => 50])
            ->assertOk()
            ->assertJsonPath('price', '50.00');

        $this->assertEquals(50.0, (float) $service->fresh()->price);
        $this->assertSame('Corte de cabelo', $service->fresh()->name);
    }

    public function test_rejects_update_outside_the_limits(): void
    {
        $service = Service::create($this->payload());

        $this->patchJson("/api/services/{$service->id}", ['duration' => 481, 'status' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['duration', 'status']);
    }

    public function test_deletes_a_service_keeping_the_history(): void
    {
        $service = Service::create($this->payload());

        $this->deleteJson("/api/services/{$service->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('services', [
            'id' => $service->id,
            'name' => 'Corte de cabelo',
        ]);

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/services/{$service->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }

    public function test_returns_404_for_unknown_service(): void
    {
        $this->getJson('/api/services/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }
}
