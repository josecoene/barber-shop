<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'     => 'Corte de cabelo',
            'duration' => 30,
            'price'    => 40.00,
            'status'   => 'active',
        ], $overrides);
    }

    public function test_lists_services(): void
    {
        Service::create($this->payload(['name' => 'Corte']));
        Service::create($this->payload(['name' => 'Barba']));

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_creates_a_service(): void
    {
        $this->postJson('/api/services', $this->payload())
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Corte de cabelo', 'duration' => 30]);

        $this->assertDatabaseHas('services', [
            'name'     => 'Corte de cabelo',
            'duration' => 30,
            'status'   => 'active',
        ]);
    }

    public function test_rejects_missing_required_fields(): void
    {
        $this->postJson('/api/services', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'duration', 'price', 'status']);
    }

    public function test_rejects_invalid_status(): void
    {
        $this->postJson('/api/services', $this->payload(['status' => 'foo']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_rejects_non_positive_duration(): void
    {
        $this->postJson('/api/services', $this->payload(['duration' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['duration']);
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
            ->assertOk();

        $this->assertEquals(50.0, (float) $service->fresh()->price);
        $this->assertSame('Corte de cabelo', $service->fresh()->name);
    }

    public function test_deletes_a_service(): void
    {
        $service = Service::create($this->payload());

        $this->deleteJson("/api/services/{$service->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('services', ['id' => $service->id]);
    }

    public function test_returns_404_for_unknown_service(): void
    {
        $this->getJson('/api/services/999')->assertNotFound();
    }
}
