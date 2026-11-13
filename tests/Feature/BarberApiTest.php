<?php

namespace Tests\Feature;

use App\Models\Barber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BarberApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'João Barbeiro',
            'email' => 'joao@barberflow.com',
            'phone' => '67988880000',
        ], $overrides);
    }

    public function test_lists_barbers_paginated_and_ordered_by_name(): void
    {
        Barber::create($this->payload(['name' => 'Pedro', 'email' => 'pedro@barberflow.com']));
        Barber::create($this->payload());

        $this->getJson('/api/barbers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'João Barbeiro')
            ->assertJsonPath('data.1.name', 'Pedro')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta'])
            ->assertJsonMissingPath('current_page');
    }

    public function test_lists_15_barbers_per_page_by_default(): void
    {
        foreach (range(1, 16) as $number) {
            Barber::create($this->payload([
                'name' => sprintf('Barbeiro %02d', $number),
                'email' => "barbeiro{$number}@barberflow.com",
            ]));
        }

        $this->getJson('/api/barbers')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 16)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/barbers?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Barbeiro 16');
    }

    public function test_per_page_changes_the_page_size_and_stays_in_the_page_links(): void
    {
        Barber::create($this->payload());
        Barber::create($this->payload(['name' => 'Pedro', 'email' => 'pedro@barberflow.com']));

        $response = $this->getJson('/api/barbers?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertStringContainsString('per_page=1', $response->json('links.next'));
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
        $this->getJson("/api/barbers?per_page={$perPage}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
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
        Barber::create($this->payload());

        $this->getJson("/api/barbers?page={$page}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);
    }

    public function test_searches_barbers_by_name_or_email(): void
    {
        Barber::create($this->payload());
        // Cada termo aparece só em um campo: "alves" só no nome e "navalha" só no e-mail.
        Barber::create($this->payload(['name' => 'Pedro Alves', 'email' => 'contato@navalha.com']));

        $this->getJson('/api/barbers?search=alves')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pedro Alves');

        $this->getJson('/api/barbers?search=navalha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'contato@navalha.com');

        $this->getJson('/api/barbers?search=ninguem')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_rejects_search_longer_than_100_characters(): void
    {
        $this->getJson('/api/barbers?search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);
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
            'name' => 'Sem Telefone',
            'email' => 'sem.telefone@barberflow.com',
        ])->assertCreated();
    }

    public function test_rejects_missing_required_fields(): void
    {
        $this->postJson('/api/barbers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email'])
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.')
            ->assertJsonPath('errors.email.0', 'O campo e-mail é obrigatório.');
    }

    public function test_rejects_invalid_email(): void
    {
        $this->postJson('/api/barbers', $this->payload(['email' => 'nao-e-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_rejects_fields_longer_than_the_columns(): void
    {
        $this->postJson('/api/barbers', $this->payload([
            'name' => str_repeat('a', 256),
            'email' => str_repeat('a', 244).'@barberflow.com',
            'phone' => str_repeat('9', 21),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone'])
            ->assertJsonPath('errors.name.0', 'O campo nome não pode ter mais de 255 caracteres.');
    }

    public function test_accepts_fields_at_the_column_limits(): void
    {
        // A regra email recusa parte do domínio com mais de 63 caracteres, então o domínio vai em partes menores.
        $email = str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 60).'.eee.com';
        $this->assertSame(255, strlen($email));

        $this->postJson('/api/barbers', $this->payload([
            'name' => str_repeat('a', 255),
            'email' => $email,
            'phone' => str_repeat('9', 20),
        ]))->assertCreated();
    }

    public function test_rejects_duplicate_email(): void
    {
        Barber::create($this->payload());

        $this->postJson('/api/barbers', $this->payload(['name' => 'Outro']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Este e-mail já está cadastrado.');
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
            'name' => 'João Atualizado',
            'email' => $barber->email,
        ])
            ->assertOk()
            ->assertJsonPath('name', 'João Atualizado');

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

    public function test_deleting_a_barber_keeps_only_the_name(): void
    {
        $barber = Barber::create($this->payload());

        $this->deleteJson("/api/barbers/{$barber->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('barbers', [
            'id' => $barber->id,
            'name' => 'João Barbeiro',
            'email' => "removido+{$barber->id}@barberflow.invalid",
            'phone' => null,
        ]);
        $this->assertDatabaseMissing('barbers', ['phone' => '67988880000']);
    }

    public function test_deleted_barber_leaves_the_list_and_returns_404(): void
    {
        $barber = Barber::create($this->payload());
        Barber::create($this->payload(['name' => 'Pedro', 'email' => 'pedro@barberflow.com']));

        $this->deleteJson("/api/barbers/{$barber->id}")->assertNoContent();

        $this->getJson('/api/barbers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pedro');

        $this->getJson("/api/barbers/{$barber->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }

    public function test_email_of_a_deleted_barber_can_be_registered_again(): void
    {
        $barber = Barber::create($this->payload());

        $this->deleteJson("/api/barbers/{$barber->id}")->assertNoContent();

        $this->postJson('/api/barbers', $this->payload(['name' => 'João de Volta']))
            ->assertCreated()
            ->assertJsonPath('email', 'joao@barberflow.com');
    }

    public function test_rejects_the_email_domain_reserved_for_deleted_barbers(): void
    {
        $barber = Barber::create($this->payload());

        $this->postJson('/api/barbers', $this->payload(['email' => "removido+{$barber->id}@barberflow.invalid"]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->putJson("/api/barbers/{$barber->id}", ['email' => 'Joao@BarberFlow.Invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->deleteJson("/api/barbers/{$barber->id}")->assertNoContent();
    }

    public function test_deleting_works_even_when_the_reserved_email_is_already_taken(): void
    {
        $barber = Barber::create($this->payload());

        // No MySQL, "removido+{id}@barberflow.ínvalid" (com acento) passa na validação e, para o
        // índice unique, é igual ao endereço reservado. O SQLite compara letra por letra, então o
        // teste grava o endereço reservado direto no banco, sem passar pela validação da API.
        $other = Barber::create($this->payload([
            'name' => 'Outro barbeiro',
            'email' => "removido+{$barber->id}@barberflow.invalid",
        ]));

        $this->deleteJson("/api/barbers/{$barber->id}")->assertNoContent();

        $deleted = Barber::withTrashed()->findOrFail($barber->id);
        $this->assertSoftDeleted($deleted);
        $this->assertSame('João Barbeiro', $deleted->name);
        $this->assertNull($deleted->phone);
        $this->assertMatchesRegularExpression('/^removido\+'.$barber->id.'\.[a-z0-9]{12}@barberflow\.invalid$/', $deleted->email);

        $this->assertSame("removido+{$barber->id}@barberflow.invalid", $other->fresh()->email);
    }

    public function test_returns_404_for_unknown_barber(): void
    {
        $this->getJson('/api/barbers/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }
}
