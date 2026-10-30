<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'  => 'Carlos Silva',
            'email' => 'carlos@example.com',
            'phone' => '67999990000',
        ], $overrides);
    }

    public function test_lists_clients(): void
    {
        Client::create($this->payload());
        Client::create($this->payload(['name' => 'Ana', 'email' => 'ana@example.com']));

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_creates_a_client(): void
    {
        $this->postJson('/api/clients', $this->payload())
            ->assertCreated()
            ->assertJsonFragment(['email' => 'carlos@example.com']);

        $this->assertDatabaseHas('clients', ['email' => 'carlos@example.com']);
    }

    public function test_phone_is_optional(): void
    {
        $this->postJson('/api/clients', [
            'name'  => 'Sem Telefone',
            'email' => 'sem.telefone@example.com',
        ])->assertCreated();
    }

    public function test_rejects_missing_required_fields(): void
    {
        $this->postJson('/api/clients', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_rejects_invalid_email(): void
    {
        $this->postJson('/api/clients', $this->payload(['email' => 'nao-e-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_rejects_duplicate_email(): void
    {
        Client::create($this->payload());

        $this->postJson('/api/clients', $this->payload(['name' => 'Outro']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_shows_a_client(): void
    {
        $client = Client::create($this->payload());

        $this->getJson("/api/clients/{$client->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $client->id, 'name' => 'Carlos Silva']);
    }

    public function test_updates_a_client_keeping_the_same_email(): void
    {
        $client = Client::create($this->payload());

        $this->putJson("/api/clients/{$client->id}", [
            'name'  => 'Carlos Atualizado',
            'email' => $client->email,
        ])->assertOk();

        $this->assertSame('Carlos Atualizado', $client->fresh()->name);
    }

    public function test_rejects_update_to_an_email_used_by_another_client(): void
    {
        Client::create($this->payload());
        $other = Client::create($this->payload(['name' => 'Ana', 'email' => 'ana@example.com']));

        $this->patchJson("/api/clients/{$other->id}", ['email' => 'carlos@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_deletes_a_client(): void
    {
        $client = Client::create($this->payload());

        $this->deleteJson("/api/clients/{$client->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
    }

    public function test_returns_404_for_unknown_client(): void
    {
        $this->getJson('/api/clients/999')->assertNotFound();
    }
}
