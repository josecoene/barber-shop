<?php

namespace Tests\Feature;

use App\Models\Barber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BarberApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'  => 'João Barbeiro',
            'email' => 'joao@barberflow.com',
            'phone' => '67988880000',
        ], $overrides);
    }

    public function test_lists_barbers(): void
    {
        Barber::create($this->payload());
        Barber::create($this->payload(['name' => 'Pedro', 'email' => 'pedro@barberflow.com']));

        $this->getJson('/api/barbers')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_creates_a_barber(): void
    {
        $this->postJson('/api/barbers', $this->payload())
            ->assertCreated()
            ->assertJsonFragment(['email' => 'joao@barberflow.com']);

        $this->assertDatabaseHas('barbers', ['email' => 'joao@barberflow.com']);
    }

    public function test_phone_is_optional(): void
    {
        $this->postJson('/api/barbers', [
            'name'  => 'Sem Telefone',
            'email' => 'sem.telefone@barberflow.com',
        ])->assertCreated();
    }

    public function test_rejects_missing_required_fields(): void
    {
        $this->postJson('/api/barbers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_rejects_invalid_email(): void
    {
        $this->postJson('/api/barbers', $this->payload(['email' => 'nao-e-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_rejects_duplicate_email(): void
    {
        Barber::create($this->payload());

        $this->postJson('/api/barbers', $this->payload(['name' => 'Outro']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_shows_a_barber(): void
    {
        $barber = Barber::create($this->payload());

        $this->getJson("/api/barbers/{$barber->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $barber->id, 'name' => 'João Barbeiro']);
    }

    public function test_updates_a_barber_keeping_the_same_email(): void
    {
        $barber = Barber::create($this->payload());

        $this->putJson("/api/barbers/{$barber->id}", [
            'name'  => 'João Atualizado',
            'email' => $barber->email,
        ])->assertOk();

        $this->assertSame('João Atualizado', $barber->fresh()->name);
    }

    public function test_rejects_update_to_an_email_used_by_another_barber(): void
    {
        Barber::create($this->payload());
        $other = Barber::create($this->payload(['name' => 'Pedro', 'email' => 'pedro@barberflow.com']));

        $this->patchJson("/api/barbers/{$other->id}", ['email' => 'joao@barberflow.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_deletes_a_barber(): void
    {
        $barber = Barber::create($this->payload());

        $this->deleteJson("/api/barbers/{$barber->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('barbers', ['id' => $barber->id]);
    }

    public function test_returns_404_for_unknown_barber(): void
    {
        $this->getJson('/api/barbers/999')->assertNotFound();
    }
}
