<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Barber;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BarberApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Resposta do e-mail em uso, a da regra unique (lang/pt_BR/validation.php).
     */
    private const EMAIL_TAKEN = [
        'message' => 'Este e-mail já está cadastrado.',
        'errors' => ['email' => ['Este e-mail já está cadastrado.']],
    ];

    /**
     * Consulta da regra unique de users, a última da validação do e-mail do
     * barbeiro (o e-mail dele vira o login). Depois dela, só sobram as gravações.
     */
    private const USERS_UNIQUE_RULE = '/^select count\(\*\) as aggregate from users where email /';

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
            ->assertExactJson(self::EMAIL_TAKEN);
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
            ->assertExactJson(self::EMAIL_TAKEN);
    }

    /**
     * Outro pedido grava um barbeiro com o mesmo e-mail logo depois da validação
     * (a última consulta dela é a do unique de users): o índice unique do banco
     * recusa o insert, a transação é desfeita, e a resposta é a mesma da regra.
     */
    public function test_two_requests_with_the_same_email_at_the_same_time_get_the_same_422(): void
    {
        $this->beforeQuery(self::USERS_UNIQUE_RULE, fn () => Barber::create($this->payload(['name' => 'Outro Barbeiro'])));

        $this->postJson('/api/barbers', $this->payload(['password' => 'senha-do-joao']))
            ->assertUnprocessable()
            ->assertExactJson(self::EMAIL_TAKEN);

        $this->assertSame(['Outro Barbeiro'], Barber::where('email', 'joao@barberflow.com')->pluck('name')->all());
        $this->assertDatabaseMissing('users', ['email' => 'joao@barberflow.com']);
    }

    /**
     * O login do barbeiro (users.email) também: outro pedido grava um usuário com
     * o mesmo e-mail logo antes do insert do login. O insert roda aqui na mesma
     * conexão, dentro da transação deste pedido, e some com ela quando ela é
     * desfeita; num banco de verdade, o do outro pedido já estaria gravado.
     */
    public function test_a_login_with_the_same_email_created_at_the_same_time_gets_the_same_422(): void
    {
        $this->beforeQuery('/^insert into users /', fn () => DB::table('users')->insert([
            'name' => 'Outra Pessoa',
            'email' => 'joao@barberflow.com',
            'password' => 'nao-importa',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->postJson('/api/barbers', $this->payload(['password' => 'senha-do-joao']))
            ->assertUnprocessable()
            ->assertExactJson(self::EMAIL_TAKEN);

        // O cadastro do barbeiro, gravado antes do login, foi desfeito junto.
        $this->assertDatabaseMissing('barbers', ['email' => 'joao@barberflow.com']);
    }

    public function test_update_racing_another_request_for_the_same_email_gets_the_same_422(): void
    {
        $barber = Barber::create($this->payload(['name' => 'Pedro', 'email' => 'pedro@barberflow.com']));
        $this->makeBarberUser($barber);

        $this->beforeQuery(self::USERS_UNIQUE_RULE, fn () => Barber::create($this->payload(['name' => 'Outro Barbeiro'])));

        $this->patchJson("/api/barbers/{$barber->id}", ['email' => 'joao@barberflow.com'])
            ->assertUnprocessable()
            ->assertExactJson(self::EMAIL_TAKEN);

        $this->assertSame('pedro@barberflow.com', $barber->fresh()->email);
        $this->assertSame('pedro@barberflow.com', $barber->fresh()->user->email);
    }

    public function test_update_racing_another_login_for_the_same_email_gets_the_same_422(): void
    {
        $barber = Barber::create($this->payload(['name' => 'Pedro', 'email' => 'pedro@barberflow.com']));
        $user = $this->makeBarberUser($barber);

        // O e-mail novo chega a outro login logo antes de o syncLogin gravar o deste barbeiro.
        $this->beforeQuery('/^update users /', fn () => DB::table('users')->insert([
            'name' => 'Outra Pessoa',
            'email' => 'joao@barberflow.com',
            'password' => 'nao-importa',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->patchJson("/api/barbers/{$barber->id}", ['email' => 'joao@barberflow.com'])
            ->assertUnprocessable()
            ->assertExactJson(self::EMAIL_TAKEN);

        // A troca do e-mail no cadastro, feita antes, foi desfeita com a transação.
        $this->assertSame('pedro@barberflow.com', $barber->fresh()->email);
        $this->assertSame('pedro@barberflow.com', $user->fresh()->email);
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

    public function test_barber_created_without_password_has_no_login(): void
    {
        $this->postJson('/api/barbers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('has_login', false)
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('user');

        $this->assertDatabaseMissing('users', ['email' => 'joao@barberflow.com']);
    }

    public function test_password_on_create_gives_the_barber_a_login(): void
    {
        // O login não sai na resposta: nem o user_id, nem a relação user (com o id e o perfil do login).
        $response = $this->postJson('/api/barbers', $this->payload(['password' => 'senha-do-joao']))
            ->assertCreated()
            ->assertJsonPath('has_login', true)
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('user')
            ->assertJsonMissingPath('password');

        $user = Barber::findOrFail($response->json('id'))->user;

        $this->assertSame(UserRole::Barber, $user->role);
        $this->assertSame('João Barbeiro', $user->name);
        $this->assertSame('joao@barberflow.com', $user->email);
        $this->assertNotNull($user->email_verified_at);

        $this->postJson('/api/login', ['email' => 'joao@barberflow.com', 'password' => 'senha-do-joao'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'barber');
    }

    public function test_rejects_a_short_password_and_an_email_that_is_already_a_login(): void
    {
        $this->postJson('/api/barbers', $this->payload(['password' => 'curta']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'O campo senha deve ter pelo menos 8 caracteres.');

        $client = $this->makeClientUser();

        $this->postJson('/api/barbers', $this->payload(['email' => $client->email]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Este e-mail já está cadastrado.');

        $this->assertSame(0, Barber::count());
    }

    /**
     * O Laravel não apara senha e trata texto só de espaços como vazio, sem rodar o mínimo de
     * 8 caracteres nele: a senha "        " virava a senha do login.
     */
    public function test_rejects_a_password_made_only_of_spaces(): void
    {
        $this->postJson('/api/barbers', $this->payload(['password' => str_repeat(' ', 8)]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'O campo senha não pode ter só espaços.');

        $this->assertSame(0, Barber::count());
        $this->assertDatabaseMissing('users', ['email' => 'joao@barberflow.com']);

        // Sem senha (null, como a tela manda o campo vazio) continua valendo: o barbeiro fica sem login.
        $this->postJson('/api/barbers', $this->payload(['password' => null]))
            ->assertCreated()
            ->assertJsonPath('has_login', false);
    }

    public function test_password_of_spaces_on_update_returns_422_even_when_the_email_is_already_a_login(): void
    {
        $barber = Barber::create($this->payload());
        // Outra pessoa já entra com o e-mail que está no cadastro do barbeiro.
        $this->makeClientUser(Client::create(['name' => 'Cliente', 'email' => 'joao@barberflow.com']));

        // Antes, o filled() via a senha de espaços como vazia: o e-mail não passava pelo unique de users
        // e o banco recusava o login repetido com erro 500.
        $this->putJson("/api/barbers/{$barber->id}", ['password' => "\t".str_repeat(' ', 8)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password'])
            ->assertJsonPath('errors.password.0', 'O campo senha não pode ter só espaços.');

        $this->assertNull($barber->fresh()->user_id);
    }

    public function test_password_on_update_creates_the_login_of_a_barber_without_one(): void
    {
        $barber = Barber::create($this->payload());

        $this->putJson("/api/barbers/{$barber->id}", ['password' => 'senha-do-joao'])
            ->assertOk()
            ->assertJsonPath('has_login', true)
            ->assertJsonMissingPath('user');

        $this->assertSame('joao@barberflow.com', $barber->fresh()->user->email);
    }

    public function test_password_without_email_on_update_needs_the_current_email_to_be_free(): void
    {
        $barber = Barber::create($this->payload());
        // Outra pessoa já entra com o e-mail que está no cadastro do barbeiro.
        $this->makeClientUser(Client::create(['name' => 'Cliente', 'email' => 'joao@barberflow.com']));

        $this->putJson("/api/barbers/{$barber->id}", ['password' => 'senha-do-joao'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertNull($barber->fresh()->user_id);
    }

    public function test_admin_resets_the_barber_password_and_the_old_logins_end(): void
    {
        $barber = Barber::create($this->payload());
        $user = $this->makeBarberUser($barber);
        $user->createToken('celular');

        $this->putJson("/api/barbers/{$barber->id}", ['password' => 'senha-nova-123'])
            ->assertOk()
            ->assertJsonPath('has_login', true)
            ->assertJsonMissingPath('user');

        $this->assertTrue(Hash::check('senha-nova-123', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/login', ['email' => $barber->email, 'password' => self::PASSWORD])
            ->assertUnprocessable();

        $this->postJson('/api/login', ['email' => $barber->email, 'password' => 'senha-nova-123'])
            ->assertOk();
    }

    public function test_name_and_email_changes_reach_the_login(): void
    {
        $barber = Barber::create($this->payload());
        $user = $this->makeBarberUser($barber);
        $password = $user->password;

        // A resposta tem o mesmo formato do GET: sem o login que o syncLogin carregou.
        $this->patchJson("/api/barbers/{$barber->id}", ['name' => 'João Novo', 'email' => 'joao.novo@barberflow.com'])
            ->assertOk()
            ->assertJsonPath('has_login', true)
            ->assertJsonMissingPath('user_id')
            ->assertJsonMissingPath('user');

        $user->refresh();
        $this->assertSame('João Novo', $user->name);
        $this->assertSame('joao.novo@barberflow.com', $user->email);
        $this->assertSame($password, $user->password);
    }

    public function test_deleting_a_barber_deletes_the_login_its_tokens_and_sessions(): void
    {
        $barber = Barber::create($this->payload());
        $user = $this->makeBarberUser($barber);
        $token = $user->createToken('celular')->plainTextToken;
        DB::table('sessions')->insert([
            'id' => 'sessao-do-barbeiro',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->deleteJson("/api/barbers/{$barber->id}")->assertNoContent();

        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertSoftDeleted('barbers', ['id' => $barber->id, 'user_id' => null, 'name' => 'João Barbeiro']);

        // O token que o barbeiro tinha não abre mais a API.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->assertSame(1, User::count());
    }
}
