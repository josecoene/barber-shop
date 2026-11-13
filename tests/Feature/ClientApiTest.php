<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Carlos Silva',
            'email' => 'carlos@example.com',
            'phone' => '67999990000',
        ], $overrides);
    }

    public function test_lists_clients_paginated_and_ordered_by_name(): void
    {
        Client::create($this->payload());
        Client::create($this->payload(['name' => 'Ana', 'email' => 'ana@example.com']));

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.1.name', 'Carlos Silva')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta'])
            ->assertJsonMissingPath('current_page');
    }

    public function test_lists_15_clients_per_page_by_default(): void
    {
        foreach (range(1, 16) as $number) {
            Client::create($this->payload([
                'name' => sprintf('Cliente %02d', $number),
                'email' => "cliente{$number}@example.com",
            ]));
        }

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.total', 16)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/clients?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Cliente 16');
    }

    public function test_per_page_changes_the_page_size_and_stays_in_the_page_links(): void
    {
        Client::create($this->payload());
        Client::create($this->payload(['name' => 'Ana', 'email' => 'ana@example.com']));

        $response = $this->getJson('/api/clients?per_page=1&search=example')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertStringContainsString('per_page=1', $response->json('links.next'));
        $this->assertStringContainsString('search=example', $response->json('links.next'));
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
        $this->getJson("/api/clients?per_page={$perPage}")
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
        Client::create($this->payload());

        $this->getJson("/api/clients?page={$page}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);
    }

    public function test_searches_clients_by_name_or_email(): void
    {
        Client::create($this->payload());
        Client::create($this->payload(['name' => 'Ana Souza', 'email' => 'ana@gmail.com']));

        $this->getJson('/api/clients?search=souza')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ana Souza');

        $this->getJson('/api/clients?search=gmail')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'ana@gmail.com');

        $this->getJson('/api/clients?search=ninguem')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_rejects_search_longer_than_100_characters(): void
    {
        $this->getJson('/api/clients?search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search']);
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
            'name' => 'Sem Telefone',
            'email' => 'sem.telefone@example.com',
        ])->assertCreated();
    }

    public function test_rejects_missing_required_fields(): void
    {
        $this->postJson('/api/clients', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email'])
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.')
            ->assertJsonPath('errors.email.0', 'O campo e-mail é obrigatório.');
    }

    public function test_rejects_invalid_email(): void
    {
        $this->postJson('/api/clients', $this->payload(['email' => 'nao-e-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'O campo e-mail deve ser um endereço de e-mail válido.');
    }

    public function test_rejects_fields_longer_than_the_columns(): void
    {
        $this->postJson('/api/clients', $this->payload([
            'name' => str_repeat('a', 256),
            'email' => str_repeat('a', 244).'@example.com.br',
            'phone' => str_repeat('9', 21),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'phone'])
            ->assertJsonPath('errors.phone.0', 'O campo telefone não pode ter mais de 20 caracteres.');
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
            'name' => 'Carlos Atualizado',
            'email' => $client->email,
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Carlos Atualizado');

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

    public function test_deleting_a_client_anonymizes_the_personal_data(): void
    {
        $client = Client::create($this->payload());

        $this->deleteJson("/api/clients/{$client->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('clients', [
            'id' => $client->id,
            'name' => 'Cliente removido',
            'email' => "removido+{$client->id}@barberflow.invalid",
            'phone' => null,
        ]);
        $this->assertDatabaseMissing('clients', ['email' => 'carlos@example.com']);
        $this->assertDatabaseMissing('clients', ['name' => 'Carlos Silva']);
    }

    public function test_deleted_client_leaves_the_list_and_returns_404(): void
    {
        $client = Client::create($this->payload());
        Client::create($this->payload(['name' => 'Ana', 'email' => 'ana@example.com']));

        $this->deleteJson("/api/clients/{$client->id}")->assertNoContent();

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ana');

        $this->getJson("/api/clients/{$client->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);

        $this->deleteJson("/api/clients/{$client->id}")
            ->assertNotFound();
    }

    public function test_email_of_a_deleted_client_can_be_registered_again(): void
    {
        $client = Client::create($this->payload());

        $this->deleteJson("/api/clients/{$client->id}")->assertNoContent();

        $this->postJson('/api/clients', $this->payload())
            ->assertCreated()
            ->assertJsonPath('email', 'carlos@example.com');

        $this->assertSame(1, Client::where('email', 'carlos@example.com')->count());
    }

    public function test_rejects_the_email_domain_reserved_for_deleted_clients(): void
    {
        $client = Client::create($this->payload());

        // Se esse e-mail entrasse, a exclusão do cliente acima bateria no unique do banco.
        foreach (["removido+{$client->id}@barberflow.invalid", "REMOVIDO+{$client->id}@BarberFlow.INVALID"] as $email) {
            $this->postJson('/api/clients', $this->payload(['email' => $email]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', 'O domínio barberflow.invalid é reservado para cadastros excluídos. Use outro e-mail.');
        }

        $this->patchJson("/api/clients/{$client->id}", ['email' => 'outro@barberflow.invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->deleteJson("/api/clients/{$client->id}")->assertNoContent();
    }

    public function test_deleting_works_even_when_the_reserved_email_is_already_taken(): void
    {
        $client = Client::create($this->payload());

        // No MySQL, "removido+{id}@barberflow.ínvalid" (com acento) passa na validação e, para o
        // índice unique, é igual ao endereço reservado. O SQLite compara letra por letra, então o
        // teste grava o endereço reservado direto no banco, sem passar pela validação da API.
        $other = Client::create($this->payload([
            'name' => 'Outra pessoa',
            'email' => "removido+{$client->id}@barberflow.invalid",
        ]));

        $this->deleteJson("/api/clients/{$client->id}")->assertNoContent();

        $deleted = Client::withTrashed()->findOrFail($client->id);
        $this->assertSoftDeleted($deleted);
        $this->assertSame('Cliente removido', $deleted->name);
        $this->assertNull($deleted->phone);
        $this->assertMatchesRegularExpression('/^removido\+'.$client->id.'\.[a-z0-9]{12}@barberflow\.invalid$/', $deleted->email);

        $this->assertSame("removido+{$client->id}@barberflow.invalid", $other->fresh()->email);
    }

    public function test_returns_404_for_unknown_client(): void
    {
        $this->getJson('/api/clients/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }
}
