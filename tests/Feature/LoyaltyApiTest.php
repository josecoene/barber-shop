<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fidelidade (App\Services\LoyaltyService): o GET /api/clients/{client}/loyalty,
 * o prêmio no POST /api/payments e a contagem na listagem de clientes. Vale um
 * prêmio a cada 3 visitas (config(['barberflow.loyalty.visits' => 3])), com o
 * desconto padrão de 100%, e os testes rodam como admin, salvo onde o perfil é
 * o assunto. A trava no MySQL fica no LoyaltyConcurrencyTest.
 *
 * O "agora" fica fixo na segunda-feira 16/11/2026 ao meio-dia. Os atendimentos
 * são gravados direto no banco, já concluídos, de meia em meia hora a partir
 * da segunda anterior às 09:00.
 */
class LoyaltyApiTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const LAST_MONDAY = '2026-11-09';

    private const FORBIDDEN = ['message' => 'Você não tem permissão para esta ação.'];

    private const AMOUNT_WITH_REWARD = 'Com o prêmio de fidelidade, o valor é calculado pelo desconto: não envie o valor.';

    private const APPOINTMENT_NOT_FOUND = 'O agendamento informado não foi encontrado entre os deste cliente.';

    private Client $client;

    private Barber $barber;

    private Service $service;

    /**
     * Próximo horário livre para os atendimentos gravados no banco.
     */
    private int $slot = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 12:00:00'));
        config(['barberflow.loyalty.visits' => 3]);
        $this->actingAsAdmin();

        $this->client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
    }

    /**
     * Atendimento concluído de 30 minutos com o Pedro, gravado direto no banco.
     */
    private function visit(array $overrides = [], ?Client $client = null): Appointment
    {
        $start = Carbon::parse(self::LAST_MONDAY.' 09:00:00')->addMinutes(30 * $this->slot++);

        return Appointment::create(array_merge([
            'client_id' => ($client ?? $this->client)->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'status' => 'completed',
            'price' => 35,
        ], $overrides));
    }

    /**
     * $count atendimentos concluídos, já pagos no Pix pelo preço cheio.
     *
     * @return list<Appointment>
     */
    private function paidVisits(int $count, ?Client $client = null): array
    {
        $visits = [];

        for ($i = 0; $i < $count; $i++) {
            $visits[] = $appointment = $this->visit(client: $client);
            $this->pay($appointment);
        }

        return $visits;
    }

    /**
     * Pagamento gravado direto no banco, sem passar pelas regras: pago no Pix,
     * com o preço do agendamento.
     */
    private function pay(Appointment $appointment, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'appointment_id' => $appointment->id,
            'amount' => $appointment->price,
            'method' => 'pix',
            'status' => 'paid',
            'paid_at' => now(),
        ], $overrides));
    }

    private function loyalty(?Client $client = null, array $query = []): TestResponse
    {
        $uri = '/api/clients/'.($client ?? $this->client)->id.'/loyalty';

        return $this->getJson($query === [] ? $uri : $uri.'?'.http_build_query($query));
    }

    private function payWithReward(Appointment $appointment, array $fields = []): TestResponse
    {
        return $this->postJson('/api/payments', array_merge([
            'appointment_id' => $appointment->id,
            'method' => 'cash',
            'use_loyalty_reward' => true,
        ], $fields));
    }

    /**
     * O resumo de fidelidade que a API devolve, com 3 visitas por prêmio e 100% de desconto.
     */
    private function summary(int $visits, int $earned, int $used): array
    {
        return [
            'visits' => $visits,
            'visits_per_reward' => 3,
            'progress' => $visits % 3,
            'rewards_earned' => $earned,
            'rewards_used' => $used,
            'available_rewards' => max(0, $earned - $used),
            'discount_percent' => 100,
        ];
    }

    private function noReward(int $progress): string
    {
        return "O cliente ainda não tem prêmio de fidelidade disponível: {$progress} de 3 visitas, sem contar este atendimento.";
    }

    public function test_client_without_visits_starts_at_zero(): void
    {
        $this->loyalty()
            ->assertOk()
            ->assertExactJson(['client_id' => $this->client->id, ...$this->summary(0, 0, 0)]);
    }

    public function test_only_completed_appointments_of_the_client_are_visits(): void
    {
        $this->paidVisits(1);
        $this->visit();

        // Não contam: o que ainda vai acontecer, o cancelado, a falta, o em atendimento e o de outra pessoa.
        $this->visit(['status' => 'scheduled', 'date' => '2026-11-18']);
        $this->visit(['status' => 'cancelled']);
        $this->visit(['status' => 'no_show']);
        $this->visit(['status' => 'in_progress']);
        $this->visit(client: Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']));

        $this->loyalty()
            ->assertOk()
            ->assertExactJson(['client_id' => $this->client->id, ...$this->summary(2, 0, 0)]);
    }

    public static function visitCounts(): array
    {
        return [
            '2 visitas' => [2, 0],
            '3 visitas: 1 prêmio' => [3, 1],
            '5 visitas' => [5, 1],
            '6 visitas: 2 prêmios' => [6, 2],
            '7 visitas' => [7, 2],
        ];
    }

    #[DataProvider('visitCounts')]
    public function test_every_3_visits_earn_a_reward(int $visits, int $earned): void
    {
        $this->paidVisits($visits);

        $this->loyalty()
            ->assertOk()
            ->assertExactJson(['client_id' => $this->client->id, ...$this->summary($visits, $earned, 0)]);
    }

    public function test_paying_with_the_reward_gives_the_discount(): void
    {
        $this->paidVisits(3);
        $fourth = $this->visit();

        $response = $this->payWithReward($fourth)
            ->assertCreated()
            ->assertJsonPath('appointment_id', $fourth->id)
            ->assertJsonPath('amount', '0.00')
            ->assertJsonPath('discount', '35.00')
            ->assertJsonPath('loyalty_reward', true)
            ->assertJsonPath('method', 'cash')
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('paid_at', now()->toJSON());

        $this->assertDatabaseHas('payments', [
            'id' => $response->json('id'),
            'appointment_id' => $fourth->id,
            'amount' => 0,
            'discount' => 35,
            'loyalty_reward' => true,
        ]);

        // O prêmio foi usado, e o atendimento pago com ele não conta como visita.
        $this->loyalty()->assertOk()->assertExactJson(['client_id' => $this->client->id, ...$this->summary(3, 1, 1)]);
    }

    public static function discounts(): array
    {
        return [
            'grátis' => [100, 35, '35.00', '0.00'],
            'metade, arredondada no centavo' => [50, '35.55', '17.78', '17.77'],
            '10%' => [10, 40, '4.00', '36.00'],
        ];
    }

    #[DataProvider('discounts')]
    public function test_the_discount_follows_the_config(int $percent, int|string $price, string $discount, string $amount): void
    {
        config(['barberflow.loyalty.discount_percent' => $percent]);
        $this->paidVisits(3);

        $this->payWithReward($this->visit(['price' => $price]))
            ->assertCreated()
            ->assertJsonPath('discount', $discount)
            ->assertJsonPath('amount', $amount);

        $this->loyalty()->assertOk()->assertJsonPath('discount_percent', $percent);
    }

    public function test_refuses_the_reward_without_one_available(): void
    {
        $this->paidVisits(1);
        $second = $this->visit();

        $this->payWithReward($second)
            ->assertUnprocessable()
            ->assertJsonPath('errors.use_loyalty_reward.0', $this->noReward(1));

        $this->assertSame(0, Payment::query()->where('appointment_id', $second->id)->count());
    }

    public function test_the_appointment_being_paid_does_not_earn_its_own_reward(): void
    {
        // Três concluídos sem pagamento: o cliente tem 3 visitas e um prêmio, mas o prêmio é do próximo.
        [, , $third] = [$this->visit(), $this->visit(), $this->visit()];

        $this->loyalty()->assertOk()->assertJsonPath('visits', 3)->assertJsonPath('available_rewards', 1);

        // A conta do pagamento do terceiro deixa ele de fora, a mesma do POST /api/payments.
        $this->loyalty(query: ['appointment_id' => $third->id])
            ->assertOk()
            ->assertExactJson(['client_id' => $this->client->id, ...$this->summary(2, 0, 0)]);

        $this->payWithReward($third)
            ->assertUnprocessable()
            ->assertJsonPath('errors.use_loyalty_reward.0', $this->noReward(2));

        // Pago sem o prêmio, e o quarto já sai com ele.
        $this->postJson('/api/payments', ['appointment_id' => $third->id, 'method' => 'pix'])
            ->assertCreated()
            ->assertJsonPath('discount', '0.00')
            ->assertJsonPath('loyalty_reward', false);

        $fourth = $this->visit();

        $this->loyalty(query: ['appointment_id' => $fourth->id])->assertOk()->assertJsonPath('available_rewards', 1);
        $this->payWithReward($fourth)->assertCreated()->assertJsonPath('amount', '0.00');
    }

    public static function amountsWithTheReward(): array
    {
        return [
            'outro valor' => [10],
            'zero' => [0],
        ];
    }

    #[DataProvider('amountsWithTheReward')]
    public function test_amount_sent_with_the_reward_is_refused(int $amount): void
    {
        $this->paidVisits(3);
        $fourth = $this->visit();

        $this->payWithReward($fourth, ['amount' => $amount])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', self::AMOUNT_WITH_REWARD);

        $this->assertSame(0, Payment::query()->where('appointment_id', $fourth->id)->count());

        // Sem valor (ou nulo), o desconto calcula.
        $this->payWithReward($fourth, ['amount' => null])->assertCreated()->assertJsonPath('amount', '0.00');
    }

    public function test_the_appointment_paid_with_the_reward_is_not_a_visit(): void
    {
        $this->paidVisits(3);
        $this->payWithReward($this->visit())->assertCreated();

        $this->paidVisits(2);

        // 5 visitas (o pago com o prêmio fica de fora): 1 prêmio ganho e já usado.
        $this->loyalty()->assertOk()->assertExactJson(['client_id' => $this->client->id, ...$this->summary(5, 1, 1)]);

        $this->paidVisits(1);

        $this->loyalty()->assertOk()->assertExactJson(['client_id' => $this->client->id, ...$this->summary(6, 2, 1)]);
    }

    public function test_refunding_the_reward_payment_gives_the_reward_back(): void
    {
        $this->paidVisits(3);
        $id = $this->payWithReward($this->visit())->assertCreated()->json('id');

        $this->patchJson("/api/payments/{$id}/status", ['status' => 'refunded'])->assertOk();

        // O prêmio volta, e o atendimento continua fora das visitas.
        $this->loyalty()->assertOk()->assertExactJson(['client_id' => $this->client->id, ...$this->summary(3, 1, 0)]);

        $this->payWithReward($this->visit())->assertCreated()->assertJsonPath('loyalty_reward', true);

        $this->loyalty()->assertOk()->assertJsonPath('available_rewards', 0);
    }

    public function test_refunding_a_regular_payment_keeps_the_visit(): void
    {
        [$first] = $this->paidVisits(3);
        $payment = Payment::query()->where('appointment_id', $first->id)->sole();

        $this->patchJson("/api/payments/{$payment->id}/status", ['status' => 'refunded'])->assertOk();

        // O atendimento aconteceu: continua sendo visita.
        $this->loyalty()->assertOk()->assertJsonPath('visits', 3)->assertJsonPath('available_rewards', 1);
    }

    public function test_paying_without_the_reward_keeps_the_full_price_and_the_reward(): void
    {
        $this->paidVisits(3);

        foreach ([['use_loyalty_reward' => false], []] as $fields) {
            $this->postJson('/api/payments', ['appointment_id' => $this->visit()->id, 'method' => 'pix', ...$fields])
                ->assertCreated()
                ->assertJsonPath('amount', '35.00')
                ->assertJsonPath('discount', '0.00')
                ->assertJsonPath('loyalty_reward', false);
        }

        // 5 visitas: o prêmio continua lá.
        $this->loyalty()->assertOk()->assertExactJson(['client_id' => $this->client->id, ...$this->summary(5, 1, 0)]);
    }

    public function test_the_payment_json_brings_the_discount_and_the_reward(): void
    {
        $this->paidVisits(3);
        $id = $this->payWithReward($this->visit())->assertCreated()->json('id');

        $this->getJson("/api/payments/{$id}")
            ->assertOk()
            ->assertJsonPath('discount', '35.00')
            ->assertJsonPath('loyalty_reward', true);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.discount', '35.00')
            ->assertJsonPath('data.0.loyalty_reward', true);
    }

    public function test_barber_uses_the_reward_in_the_own_agenda(): void
    {
        $this->paidVisits(3);
        $fourth = $this->visit();
        $this->actingAsBarber($this->barber);

        $this->payWithReward($fourth)->assertCreated()->assertJsonPath('amount', '0.00');
    }

    public function test_admin_and_barber_see_the_loyalty_of_any_client(): void
    {
        $this->paidVisits(1);

        $this->loyalty()->assertOk()->assertJsonPath('visits', 1);

        // O barbeiro vê a fidelidade de qualquer cliente, mesmo sem atendimento na agenda dele.
        $this->actingAsBarber();

        $this->loyalty()->assertOk()->assertJsonPath('visits', 1);
    }

    public function test_client_sees_only_the_own_loyalty(): void
    {
        $this->paidVisits(1);
        $other = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']);
        $this->actingAsClient($this->client);

        $this->loyalty()->assertOk()->assertJsonPath('visits', 1);
        $this->loyalty($other)->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    public function test_client_without_the_confirmed_email_does_not_see_the_loyalty(): void
    {
        $this->actingAsClient($this->client, verified: false);

        $this->loyalty()->assertForbidden()->assertExactJson(['message' => 'Confirme seu e-mail para continuar.']);
    }

    public function test_unknown_client_gets_404(): void
    {
        $this->getJson('/api/clients/999/loyalty')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }

    public function test_appointment_id_must_be_of_the_client_and_visible_to_the_user(): void
    {
        $other = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']);
        $ofOther = $this->visit(client: $other);
        $own = $this->visit();

        $notFound = [
            'message' => self::APPOINTMENT_NOT_FOUND,
            'errors' => ['appointment_id' => [self::APPOINTMENT_NOT_FOUND]],
        ];

        $this->loyalty(query: ['appointment_id' => $ofOther->id])->assertUnprocessable()->assertExactJson($notFound);
        $this->loyalty(query: ['appointment_id' => 999])->assertUnprocessable()->assertExactJson($notFound);
        $this->loyalty(query: ['appointment_id' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('appointment_id');

        // O barbeiro de outra agenda vê a fidelidade do cliente, mas não a conta de um atendimento que não é
        // dele. A resposta é a mesma do agendamento que não existe ou que é de outro cliente: ela não conta
        // quais agendamentos das outras agendas são deste cliente.
        $this->actingAsBarber();
        $this->loyalty(query: ['appointment_id' => $own->id])->assertUnprocessable()->assertExactJson($notFound);
        $this->loyalty(query: ['appointment_id' => $ofOther->id])->assertUnprocessable()->assertExactJson($notFound);

        $this->actingAsBarber($this->barber);
        $this->loyalty(query: ['appointment_id' => $own->id])->assertOk()->assertJsonPath('visits', 0);
        $this->loyalty(query: ['appointment_id' => $ofOther->id])->assertUnprocessable()->assertExactJson($notFound);

        // O cliente, na própria fidelidade: o próprio atendimento sim, o de outro cliente não.
        $this->actingAsClient($this->client);
        $this->loyalty(query: ['appointment_id' => $own->id])->assertOk()->assertJsonPath('visits', 0);
        $this->loyalty(query: ['appointment_id' => $ofOther->id])->assertUnprocessable()->assertExactJson($notFound);
    }

    public function test_client_list_brings_the_loyalty_of_each_client(): void
    {
        $bruno = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']);
        Client::create(['name' => 'Carla', 'email' => 'carla@barberflow.test']);

        $this->paidVisits(3);
        $this->payWithReward($this->visit())->assertCreated();
        $this->visit();
        $this->paidVisits(2, $bruno);

        $this->getJson('/api/clients')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.0.loyalty', $this->summary(4, 1, 1))
            ->assertJsonPath('data.1.name', 'Bruno')
            ->assertJsonPath('data.1.loyalty', $this->summary(2, 0, 0))
            ->assertJsonPath('data.2.name', 'Carla')
            ->assertJsonPath('data.2.loyalty', $this->summary(0, 0, 0))
            // As colunas da conta ficam de fora: sai só o resumo.
            ->assertJsonMissingPath('data.0.loyalty_visits')
            ->assertJsonMissingPath('data.0.loyalty_rewards_used');
    }

    public function test_client_list_counts_the_loyalty_without_one_query_per_client(): void
    {
        $this->paidVisits(2);

        $queriesFor = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->getJson('/api/clients')->assertOk();

            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $withOneClient = $queriesFor();

        foreach (['Bruno', 'Carla', 'Davi', 'Eva'] as $name) {
            $this->paidVisits(1, Client::create(['name' => $name, 'email' => strtolower($name).'@barberflow.test']));
        }

        $this->assertSame($withOneClient, $queriesFor());
        $this->getJson('/api/clients')->assertOk()->assertJsonCount(5, 'data');
    }
}
