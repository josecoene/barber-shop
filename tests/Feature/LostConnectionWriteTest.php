<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Gravação que perde a conexão depois que o banco gravou. Com o DB_READ_TIMEOUT
 * (AppServiceProvider), a espera pela resposta de um INSERT lento (o banco sem
 * CPU ou esperando uma trava) desiste com o erro 2006 do MySQL, e o banco pode
 * já ter gravado. Fora de uma transação, o Laravel acha que a conexão caiu antes
 * do comando e repete o INSERT numa conexão nova: o serviço sairia em dobro, e o
 * cliente e a conta de cliente sairiam com o 422 de e-mail já cadastrado para o
 * que acabou de ser criado (e a conta, sem o e-mail de confirmação). Dentro de
 * uma transação, ele não repete, e a conexão que cai sem COMMIT desfaz a
 * gravação: a resposta é um erro e nada fica gravado. O loseConnectionAfter() do
 * tests/TestCase.php faz o comando rodar e dar o erro logo depois.
 */
class LostConnectionWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();
    }

    public function test_a_new_service_is_not_saved_twice(): void
    {
        $this->actingAsAdmin();
        $this->loseConnectionAfter('/^insert into services /');

        $this->assertDatabaseErrorWithoutRetry(
            $this->postJson('/api/services', ['name' => 'Corte de cabelo', 'duration' => 30, 'price' => 35])
        );

        $this->assertSame(0, Service::count());
    }

    public function test_a_new_client_is_not_saved_twice_nor_refused_as_a_taken_email(): void
    {
        $this->actingAsAdmin();
        $this->loseConnectionAfter('/^insert into clients /');

        $this->assertDatabaseErrorWithoutRetry(
            $this->postJson('/api/clients', ['name' => 'Maria Souza', 'email' => 'maria.souza@barberflow.test'])
        );

        $this->assertSame(0, Client::count());
    }

    public function test_a_new_account_is_not_refused_as_a_taken_email(): void
    {
        Notification::fake();
        $this->loseConnectionAfter('/^insert into users /');

        $this->assertDatabaseErrorWithoutRetry($this->postJson('/api/register', [
            'name' => 'Maria Souza',
            'email' => 'maria.souza@barberflow.test',
            'password' => 'senha-da-cliente',
            'password_confirmation' => 'senha-da-cliente',
        ]));

        // A pessoa pode tentar de novo: a conta não ficou criada pela metade.
        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    /**
     * O pedido dá o erro do banco (500, com o erro no log sem o SQL), e não a
     * resposta da gravação repetida (201 em dobro ou 422 de e-mail em uso).
     */
    private function assertDatabaseErrorWithoutRetry(TestResponse $response): void
    {
        $response->assertInternalServerError();

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'Erro no banco de dados.');
    }
}
