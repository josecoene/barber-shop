<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET /api/dashboard: os números de um cenário conhecido, conferidos um a um,
 * o período padrão (mês atual no fuso do app), os limites do período e quem
 * pode ver (só o admin).
 *
 * O "agora" fica fixo na segunda-feira 16/11/2026 às 08:00, e os cadastros são
 * do domingo 01/11, antes de todos os períodos dos testes (barbeiro cadastrado
 * depois do fim do período não conta). Agendamentos e pagamentos são gravados
 * direto no banco, sem passar pelas regras. O expediente é o padrão: 09:00 às
 * 19:00 (600 minutos), de segunda a sábado.
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN = ['message' => 'Você não tem permissão para esta ação.'];

    /**
     * A semana do cenário: de segunda 09/11 a sábado 14/11/2026, 6 dias de expediente.
     */
    private const WEEK = 'from=2026-11-09&to=2026-11-14';

    private Barber $pedro;

    private Barber $joao;

    private Client $ana;

    private Client $bruno;

    private Service $corte;

    private Service $barba;

    private Service $combo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-01 08:00:00'));

        $this->pedro = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $this->joao = Barber::create(['name' => 'João', 'email' => 'joao@barberflow.test']);
        $this->ana = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->bruno = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']);
        $this->corte = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
        $this->barba = Service::create(['name' => 'Barba', 'duration' => 30, 'price' => 25]);
        $this->combo = Service::create(['name' => 'Corte e barba', 'duration' => 60, 'price' => 55]);

        $this->travelTo(Carbon::parse('2026-11-16 08:00:00'));
    }

    /**
     * Agendamento gravado direto no banco, com o término pela duração do serviço.
     */
    private function appointment(Barber $barber, Client $client, Service $service, string $date, string $start, string $status): Appointment
    {
        return Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'date' => $date,
            'start_time' => $start,
            'end_time' => Carbon::parse("{$date} {$start}")->addMinutes($service->duration)->format('H:i:s'),
            'status' => $status,
            'price' => $service->price,
        ]);
    }

    private function payment(Appointment $appointment, string $method, string $status, float $amount): Payment
    {
        return Payment::create([
            'appointment_id' => $appointment->id,
            'amount' => $amount,
            'method' => $method,
            'status' => $status,
            'paid_at' => $status === 'pending' ? null : now(),
        ]);
    }

    /**
     * A semana de 09/11 a 14/11, com 13 agendamentos de 2 barbeiros e 3 serviços
     * em todos os status, e mais 2 atendimentos pagos fora da semana.
     */
    private function week(): void
    {
        // Pedro
        $this->payment($this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-09', '10:00', 'completed'), 'cash', 'paid', 35);
        $this->payment($this->appointment($this->pedro, $this->bruno, $this->combo, '2026-11-09', '11:00', 'completed'), 'pix', 'paid', 55);
        $this->payment($this->appointment($this->pedro, $this->ana, $this->barba, '2026-11-10', '10:00', 'completed'), 'credit_card', 'refunded', 25);
        $this->appointment($this->pedro, $this->bruno, $this->corte, '2026-11-10', '14:00', 'no_show');
        $this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-11', '09:00', 'cancelled');
        $this->appointment($this->pedro, $this->bruno, $this->combo, '2026-11-14', '10:00', 'scheduled');

        // João: o pagamento do corte de terça saiu por 40, acima do preço.
        $this->payment($this->appointment($this->joao, $this->ana, $this->corte, '2026-11-10', '10:00', 'completed'), 'pix', 'paid', 40);
        $this->payment($this->appointment($this->joao, $this->bruno, $this->combo, '2026-11-12', '15:00', 'completed'), 'debit_card', 'pending', 55);
        $this->appointment($this->joao, $this->ana, $this->barba, '2026-11-13', '09:00', 'completed');
        $this->appointment($this->joao, $this->bruno, $this->corte, '2026-11-13', '16:00', 'confirmed');
        $this->appointment($this->joao, $this->ana, $this->barba, '2026-11-13', '17:00', 'in_progress');
        $this->appointment($this->joao, $this->bruno, $this->corte, '2026-11-14', '09:00', 'no_show');
        $this->payment($this->appointment($this->joao, $this->bruno, $this->barba, '2026-11-12', '10:00', 'completed'), 'credit_card', 'paid', 25);

        // Fora da semana: o sábado anterior e a segunda seguinte.
        $this->payment($this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-07', '10:00', 'completed'), 'cash', 'paid', 35);
        $this->payment($this->appointment($this->joao, $this->bruno, $this->corte, '2026-11-16', '10:00', 'completed'), 'cash', 'paid', 35);
    }

    public function test_numbers_of_a_known_week(): void
    {
        $this->week();
        $this->actingAsAdmin();

        $response = $this->getJson('/api/dashboard?'.self::WEEK)->assertOk();

        $response->assertJsonPath('period', ['from' => '2026-11-09', 'to' => '2026-11-14', 'days' => 6]);

        // Faturamento: só os pagos (35 + 55 + 40 + 25); o estornado (25) e o pendente (55) ficam de fora.
        $response->assertJsonPath('revenue.total', '155.00')
            ->assertJsonPath('revenue.payments', 4)
            ->assertJsonPath('revenue.average_ticket', '38.75')
            ->assertJsonPath('revenue.by_method', [
                ['method' => 'cash', 'label' => 'Dinheiro', 'payments' => 1, 'total' => '35.00'],
                ['method' => 'pix', 'label' => 'Pix', 'payments' => 2, 'total' => '95.00'],
                ['method' => 'credit_card', 'label' => 'Cartão de crédito', 'payments' => 1, 'total' => '25.00'],
                ['method' => 'debit_card', 'label' => 'Cartão de débito', 'payments' => 0, 'total' => '0.00'],
            ])
            ->assertJsonPath('revenue.by_day', [
                ['date' => '2026-11-09', 'payments' => 2, 'total' => '90.00'],
                ['date' => '2026-11-10', 'payments' => 1, 'total' => '40.00'],
                ['date' => '2026-11-11', 'payments' => 0, 'total' => '0.00'],
                ['date' => '2026-11-12', 'payments' => 1, 'total' => '25.00'],
                ['date' => '2026-11-13', 'payments' => 0, 'total' => '0.00'],
                ['date' => '2026-11-14', 'payments' => 0, 'total' => '0.00'],
            ]);

        // 13 agendamentos na semana; faltas: 2 ÷ (7 concluídos + 2 faltas) = 22,2%.
        $response->assertJsonPath('appointments.total', 13)
            ->assertJsonPath('appointments.by_status', [
                ['status' => 'scheduled', 'label' => 'Agendado', 'count' => 1],
                ['status' => 'confirmed', 'label' => 'Confirmado', 'count' => 1],
                ['status' => 'in_progress', 'label' => 'Em atendimento', 'count' => 1],
                ['status' => 'completed', 'label' => 'Concluído', 'count' => 7],
                ['status' => 'cancelled', 'label' => 'Cancelado', 'count' => 1],
                ['status' => 'no_show', 'label' => 'Faltou', 'count' => 2],
            ])
            ->assertJsonPath('appointments.no_show_rate', 22.2);

        // Ocupação: Pedro 180 min (30 + 60 + 30 + 60) e João 210 min (30 + 60 + 30 + 30 + 30 + 30), sem
        // o cancelado e as faltas. Disponível: 600 min × 6 dias × 2 barbeiros = 7200. 390 ÷ 7200 = 5,4%.
        $response->assertJsonPath('occupancy', [
            'rate' => 5.4,
            'occupied_minutes' => 390,
            'available_minutes' => 7200,
            'minutes_per_day' => 600,
            'business_days' => 6,
            'barbers' => 2,
        ]);

        // Barba tem mais concluídos (3); Corte e Corte e barba empatam em 2 e o faturamento desempata.
        $response->assertJsonPath('top_services', [
            ['id' => $this->barba->id, 'name' => 'Barba', 'completed' => 3, 'revenue' => '25.00'],
            ['id' => $this->corte->id, 'name' => 'Corte', 'completed' => 2, 'revenue' => '75.00'],
            ['id' => $this->combo->id, 'name' => 'Corte e barba', 'completed' => 2, 'revenue' => '55.00'],
        ]);

        $response->assertJsonPath('barbers', [
            ['id' => $this->pedro->id, 'name' => 'Pedro', 'completed' => 3, 'no_show' => 1, 'revenue' => '90.00'],
            ['id' => $this->joao->id, 'name' => 'João', 'completed' => 4, 'no_show' => 1, 'revenue' => '65.00'],
        ]);
    }

    public function test_the_free_cut_of_the_loyalty_is_not_a_payment(): void
    {
        $this->actingAsAdmin();

        // Segunda 09/11, três cortes concluídos do Pedro: um pago no Pix pelo preço cheio, o corte grátis da
        // fidelidade (100% de desconto, valor zero, registrado em dinheiro) e um prêmio com metade do
        // desconto (de quando o LOYALTY_DISCOUNT_PERCENT era 50), pago no Pix.
        $this->payment($this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-09', '10:00', 'completed'), 'pix', 'paid', 35);

        foreach (['11:00' => ['cash', 0, 35], '12:00' => ['pix', 17.5, 17.5]] as $time => [$method, $amount, $discount]) {
            Payment::create([
                'appointment_id' => $this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-09', $time, 'completed')->id,
                'amount' => $amount,
                'discount' => $discount,
                'loyalty_reward' => true,
                'method' => $method,
                'status' => 'paid',
                'paid_at' => now(),
            ]);
        }

        // Entraram 35 + 17,50 = 52,50 em 2 pagamentos: o corte grátis não trouxe dinheiro e fica fora da
        // contagem, do ticket médio (52,50 ÷ 2, e não ÷ 3) e do Dinheiro.
        $this->getJson('/api/dashboard?'.self::WEEK)
            ->assertOk()
            ->assertJsonPath('revenue.total', '52.50')
            ->assertJsonPath('revenue.payments', 2)
            ->assertJsonPath('revenue.average_ticket', '26.25')
            ->assertJsonPath('revenue.by_method.0', ['method' => 'cash', 'label' => 'Dinheiro', 'payments' => 0, 'total' => '0.00'])
            ->assertJsonPath('revenue.by_method.1', ['method' => 'pix', 'label' => 'Pix', 'payments' => 2, 'total' => '52.50'])
            ->assertJsonPath('revenue.by_day.0', ['date' => '2026-11-09', 'payments' => 2, 'total' => '52.50'])
            // O atendimento grátis aconteceu: conta como concluído no status, no serviço e no barbeiro.
            ->assertJsonPath('appointments.by_status.3', ['status' => 'completed', 'label' => 'Concluído', 'count' => 3])
            ->assertJsonPath('top_services', [
                ['id' => $this->corte->id, 'name' => 'Corte', 'completed' => 3, 'revenue' => '52.50'],
            ])
            ->assertJsonPath('barbers.0', ['id' => $this->pedro->id, 'name' => 'Pedro', 'completed' => 3, 'no_show' => 0, 'revenue' => '52.50']);
    }

    public function test_defaults_to_the_current_month_in_the_app_timezone(): void
    {
        // 01:30 do dia 1º de dezembro em UTC ainda é 30 de novembro, 22:30, em São Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 01:30:00', 'UTC'));
        $this->actingAsAdmin();

        $this->appointment($this->pedro, $this->ana, $this->corte, '2026-10-31', '10:00', 'completed');
        $this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-02', '10:00', 'completed');
        $this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-30', '10:00', 'no_show');
        $this->appointment($this->pedro, $this->ana, $this->corte, '2026-12-01', '10:00', 'scheduled');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('period', ['from' => '2026-11-01', 'to' => '2026-11-30', 'days' => 30])
            ->assertJsonPath('appointments.total', 2)
            ->assertJsonPath('appointments.no_show_rate', 50.0)
            ->assertJsonCount(30, 'revenue.by_day')
            // Novembro de 2026 tem 25 dias de segunda a sábado.
            ->assertJsonPath('occupancy.business_days', 25);
    }

    public function test_period_without_movement(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/dashboard?from=2026-11-02&to=2026-11-03')
            ->assertOk()
            ->assertJsonPath('revenue.total', '0.00')
            ->assertJsonPath('revenue.payments', 0)
            ->assertJsonPath('revenue.average_ticket', '0.00')
            ->assertJsonPath('revenue.by_day', [
                ['date' => '2026-11-02', 'payments' => 0, 'total' => '0.00'],
                ['date' => '2026-11-03', 'payments' => 0, 'total' => '0.00'],
            ])
            ->assertJsonPath('appointments.total', 0)
            ->assertJsonPath('appointments.no_show_rate', null)
            ->assertJsonPath('occupancy.rate', 0.0)
            ->assertJsonPath('occupancy.available_minutes', 2400)
            ->assertJsonPath('top_services', [])
            ->assertJsonPath('barbers', [
                ['id' => $this->joao->id, 'name' => 'João', 'completed' => 0, 'no_show' => 0, 'revenue' => '0.00'],
                ['id' => $this->pedro->id, 'name' => 'Pedro', 'completed' => 0, 'no_show' => 0, 'revenue' => '0.00'],
            ]);
    }

    public function test_occupancy_has_no_rate_without_business_days_or_barbers(): void
    {
        $this->actingAsAdmin();

        // Só o domingo 15/11: nenhum dia de expediente.
        $this->getJson('/api/dashboard?from=2026-11-15&to=2026-11-15')
            ->assertOk()
            ->assertJsonPath('occupancy.business_days', 0)
            ->assertJsonPath('occupancy.available_minutes', 0)
            ->assertJsonPath('occupancy.rate', null);

        // Nenhum barbeiro: os dois foram excluídos antes do período.
        $this->pedro->releaseEmailAndDelete();
        $this->joao->releaseEmailAndDelete();
        $this->travelTo(Carbon::parse('2026-11-30 08:00:00'));

        $this->getJson('/api/dashboard?from=2026-11-23&to=2026-11-28')
            ->assertOk()
            ->assertJsonPath('occupancy.barbers', 0)
            ->assertJsonPath('occupancy.rate', null)
            ->assertJsonPath('barbers', []);
    }

    public function test_occupancy_counts_the_barbers_not_deleted_before_the_period(): void
    {
        $this->actingAsAdmin();

        // João atendeu na segunda e foi excluído na quarta 11/11: conta na semana de 09 a 14/11, em que
        // ainda trabalhou, e não conta na semana seguinte.
        $this->payment($this->appointment($this->joao, $this->ana, $this->corte, '2026-11-09', '10:00', 'completed'), 'cash', 'paid', 35);
        $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
        $this->joao->releaseEmailAndDelete();
        $this->travelTo(Carbon::parse('2026-11-23 08:00:00'));

        $this->getJson('/api/dashboard?'.self::WEEK)
            ->assertOk()
            ->assertJsonPath('occupancy.barbers', 2)
            ->assertJsonPath('occupancy.available_minutes', 7200)
            ->assertJsonPath('barbers', [
                ['id' => $this->joao->id, 'name' => 'João', 'completed' => 1, 'no_show' => 0, 'revenue' => '35.00'],
                ['id' => $this->pedro->id, 'name' => 'Pedro', 'completed' => 0, 'no_show' => 0, 'revenue' => '0.00'],
            ]);

        $this->getJson('/api/dashboard?from=2026-11-16&to=2026-11-21')
            ->assertOk()
            ->assertJsonPath('occupancy.barbers', 1)
            ->assertJsonPath('occupancy.available_minutes', 3600)
            ->assertJsonPath('barbers', [
                ['id' => $this->pedro->id, 'name' => 'Pedro', 'completed' => 0, 'no_show' => 0, 'revenue' => '0.00'],
            ]);
    }

    public function test_barbers_registered_after_the_period_do_not_count(): void
    {
        $this->actingAsAdmin();

        // Davi foi cadastrado no último dia da semana de 09 a 14/11 e conta a semana inteira (não há data
        // de contratação). Carlos foi cadastrado depois dela, na segunda 16/11: ninguém agenda no passado,
        // então ele não pode ter atendimento na semana, e a semana que já passou não muda com o cadastro dele.
        $this->travelTo(Carbon::parse('2026-11-14 18:00:00'));
        Barber::create(['name' => 'Davi', 'email' => 'davi@barberflow.test']);
        $this->travelTo(Carbon::parse('2026-11-16 08:00:00'));
        $carlos = Barber::create(['name' => 'Carlos', 'email' => 'carlos@barberflow.test']);

        // Cadastro sem data de cadastro (gravado sem os timestamps): conta.
        Barber::withoutTimestamps(fn () => Barber::create(['name' => 'Eduardo', 'email' => 'eduardo@barberflow.test']));

        $week = $this->getJson('/api/dashboard?'.self::WEEK)
            ->assertOk()
            ->assertJsonPath('occupancy.barbers', 4)
            ->assertJsonPath('occupancy.available_minutes', 14400);

        $this->assertSame(['Davi', 'Eduardo', 'João', 'Pedro'], array_column($week->json('barbers'), 'name'));

        // Na semana do cadastro, Carlos conta a semana inteira: 600 min × 6 dias × 5 barbeiros.
        $next = $this->getJson('/api/dashboard?from=2026-11-16&to=2026-11-21')
            ->assertOk()
            ->assertJsonPath('occupancy.barbers', 5)
            ->assertJsonPath('occupancy.available_minutes', 18000);

        $this->assertContains($carlos->id, array_column($next->json('barbers'), 'id'));
    }

    public function test_a_period_ending_on_the_last_day_of_the_year_9999(): void
    {
        $this->actingAsAdmin();

        // 31/12/9999 (uma sexta) é a última data que o Y-m-d aceita. O dia seguinte vira o texto
        // "10000-01-01", que vem antes de "9999-12-31" numa comparação de texto: a conta dos dias tem de
        // comparar datas, senão não para mais.
        $this->getJson('/api/dashboard?from=9999-12-31&to=9999-12-31')
            ->assertOk()
            ->assertJsonPath('period', ['from' => '9999-12-31', 'to' => '9999-12-31', 'days' => 1])
            ->assertJsonPath('revenue.by_day', [['date' => '9999-12-31', 'payments' => 0, 'total' => '0.00']])
            ->assertJsonPath('occupancy.business_days', 1);
    }

    public function test_occupancy_follows_the_business_hours_of_the_config(): void
    {
        config(['barberflow.business' => ['opening' => '10:00', 'closing' => '12:00', 'slot_minutes' => 30, 'days' => [1, 3]]]);
        $this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-09', '10:00', 'completed');
        $this->actingAsAdmin();

        // Segunda e quarta, das 10:00 às 12:00: 120 min × 2 dias × 2 barbeiros = 480. 30 ÷ 480 = 6,25%, que vira 6,3%.
        $this->getJson('/api/dashboard?'.self::WEEK)
            ->assertOk()
            ->assertJsonPath('occupancy.minutes_per_day', 120)
            ->assertJsonPath('occupancy.business_days', 2)
            ->assertJsonPath('occupancy.available_minutes', 480)
            ->assertJsonPath('occupancy.rate', 6.3);
    }

    public function test_top_services_keeps_only_five_and_shows_deleted_services(): void
    {
        $this->actingAsAdmin();
        $services = [];

        // Seis serviços, com 1 a 6 atendimentos concluídos na segunda 09/11, cada um às suas horas.
        foreach (range(1, 6) as $index) {
            $services[$index] = Service::create(['name' => "Serviço {$index}", 'duration' => 30, 'price' => 10]);

            foreach (range(1, $index) as $slot) {
                $client = Client::create(['name' => "Cliente {$index}.{$slot}", 'email' => "cliente.{$index}.{$slot}@barberflow.test"]);
                $this->appointment($this->pedro, $client, $services[$index], '2026-11-09', sprintf('%02d:00', 8 + $slot), 'completed');
            }
        }

        $services[6]->delete();

        $response = $this->getJson('/api/dashboard?'.self::WEEK)->assertOk()->assertJsonCount(5, 'top_services');

        $this->assertSame(
            ['Serviço 6', 'Serviço 5', 'Serviço 4', 'Serviço 3', 'Serviço 2'],
            array_column($response->json('top_services'), 'name'),
        );
        $this->assertSame([6, 5, 4, 3, 2], array_column($response->json('top_services'), 'completed'));
    }

    public static function invalidPeriods(): array
    {
        return [
            'só o início' => ['from=2026-11-01', 'to', 'O campo data final é obrigatório quando data inicial estiver presente.'],
            'só o fim' => ['to=2026-11-30', 'from', 'O campo data inicial é obrigatório quando data final estiver presente.'],
            'início em outro formato' => ['from=01/11/2026&to=2026-11-30', 'from', 'O campo data inicial deve seguir o formato Y-m-d.'],
            'data que não existe' => ['from=2026-02-30&to=2026-03-01', 'from', 'O campo data inicial deve seguir o formato Y-m-d.'],
            'fim antes do início' => ['from=2026-11-30&to=2026-11-01', 'to', 'O campo data final deve ser uma data igual ou posterior a data inicial.'],
            'mais de 366 dias' => ['from=2026-01-01&to=2027-01-02', 'to', 'O período pode ter no máximo 366 dias.'],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_rejects_invalid_periods(string $query, string $field, string $message): void
    {
        $this->actingAsAdmin();

        $this->getJson("/api/dashboard?{$query}")
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", $message);
    }

    public function test_accepts_a_period_of_up_to_366_days(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/dashboard?from=2026-01-01&to=2027-01-01')
            ->assertOk()
            ->assertJsonPath('period.days', 366)
            ->assertJsonCount(366, 'revenue.by_day');

        // 2028 é bissexto: o ano inteiro tem 366 dias.
        $this->getJson('/api/dashboard?from=2028-01-01&to=2028-12-31')
            ->assertOk()
            ->assertJsonPath('period.days', 366);

        $this->getJson('/api/dashboard?from=2028-01-01&to=2029-01-01')
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', 'O período pode ter no máximo 366 dias.');
    }

    public function test_the_number_of_queries_does_not_grow_with_the_volume(): void
    {
        $this->actingAsAdmin();
        $this->payment($this->appointment($this->pedro, $this->ana, $this->corte, '2026-11-09', '10:00', 'completed'), 'cash', 'paid', 35);

        $queriesForOne = $this->countQueries(fn () => $this->getJson('/api/dashboard?'.self::WEEK)->assertOk());

        $this->week();

        $queriesForMany = $this->countQueries(fn () => $this->getJson('/api/dashboard?'.self::WEEK)->assertOk());

        $this->assertSame($queriesForOne, $queriesForMany);
    }

    public static function forbiddenProfiles(): array
    {
        return [
            'barbeiro' => ['actingAsBarber'],
            'cliente' => ['actingAsClient'],
        ];
    }

    #[DataProvider('forbiddenProfiles')]
    public function test_only_the_admin_sees_the_dashboard(string $login): void
    {
        $this->{$login}();

        $this->getJson('/api/dashboard')->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->getJson('/api/dashboard?'.self::WEEK)->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    public function test_client_without_the_confirmed_email_and_user_without_role_are_refused(): void
    {
        $this->actingAsClient(verified: false);

        $this->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Confirme seu e-mail para continuar.']);

        $user = User::create(['name' => 'Sem Perfil', 'email' => 'sem.perfil@barberflow.test', 'password' => self::PASSWORD]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')->assertForbidden()->assertExactJson(self::FORBIDDEN);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
