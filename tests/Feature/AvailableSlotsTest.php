<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET /api/barbers/{barber}/available-slots: inícios livres (H:i) do barbeiro
 * no dia para a duração do serviço. Quem pode consultar fica no
 * AppointmentAuthorizationTest.
 *
 * O "agora" fica fixo na segunda-feira 16/11/2026 às 08:00. O expediente é o
 * padrão: 09:00 às 19:00, de segunda a sábado, em fatias de 30 minutos.
 */
class AvailableSlotsTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private Barber $barber;

    private Client $client;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));
        $this->actingAsAdmin();

        $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $this->client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
    }

    private function slots(array $query = [], ?Barber $barber = null): TestResponse
    {
        $barber ??= $this->barber;
        $query += ['date' => self::MONDAY, 'service_id' => $this->service->id];

        return $this->getJson("/api/barbers/{$barber->id}/available-slots?".http_build_query($query));
    }

    /**
     * Agendamento do Pedro gravado direto no banco, na segunda.
     */
    private function appointment(string $start, string $end, string $status = 'scheduled', ?Barber $barber = null): Appointment
    {
        return Appointment::create([
            'client_id' => $this->client->id,
            'barber_id' => ($barber ?? $this->barber)->id,
            'service_id' => $this->service->id,
            'date' => self::MONDAY,
            'start_time' => $start,
            'end_time' => $end,
            'status' => $status,
            'price' => 35,
        ]);
    }

    /**
     * Horários de $first a $last (H:i), de $step em $step minutos.
     *
     * @return list<string>
     */
    private function times(string $first, string $last, int $step = 30): array
    {
        $times = [];

        for ($time = Carbon::parse("2026-01-01 {$first}"); $time->format('H:i') <= $last; $time->addMinutes($step)) {
            $times[] = $time->format('H:i');
        }

        return $times;
    }

    public function test_lists_every_slot_of_a_free_day(): void
    {
        $this->slots()
            ->assertOk()
            ->assertExactJson(['data' => $this->times('09:00', '18:30')]);

        $this->assertCount(20, $this->times('09:00', '18:30'));
    }

    public function test_taken_times_leave_the_list_and_the_back_to_back_ones_stay(): void
    {
        $this->appointment('10:00:00', '10:30:00');

        $data = $this->slots()->assertOk()->json('data');

        $this->assertNotContains('10:00', $data);
        // 09:30 termina quando o outro começa e 10:30 começa quando ele termina.
        $this->assertContains('09:30', $data);
        $this->assertContains('10:30', $data);
        $this->assertCount(19, $data);
    }

    public function test_a_longer_service_needs_the_whole_interval_free(): void
    {
        $this->appointment('10:00:00', '10:30:00');
        $hour = Service::create(['name' => 'Corte e barba', 'duration' => 60, 'price' => 60]);

        $data = $this->slots(['service_id' => $hour->id])->assertOk()->json('data');

        // 09:30 às 10:30 cruza o agendamento; 09:00 às 10:00 encosta nele.
        $this->assertNotContains('09:30', $data);
        $this->assertNotContains('10:00', $data);
        $this->assertContains('09:00', $data);
        $this->assertContains('10:30', $data);
        $this->assertSame('18:00', end($data));
    }

    public function test_a_long_service_does_not_start_where_it_would_end_after_closing(): void
    {
        $long = Service::create(['name' => 'Pigmentação', 'duration' => 90, 'price' => 90]);

        // 17:30 + 90 minutos termina às 19:00, no fechamento.
        $this->slots(['service_id' => $long->id])
            ->assertOk()
            ->assertExactJson(['data' => $this->times('09:00', '17:30')]);
    }

    public function test_cancelled_and_no_show_do_not_take_the_time(): void
    {
        $this->appointment('10:00:00', '10:30:00', 'cancelled');
        $this->appointment('11:00:00', '11:30:00', 'no_show');

        $this->slots()
            ->assertOk()
            ->assertExactJson(['data' => $this->times('09:00', '18:30')]);
    }

    public function test_appointments_of_other_barbers_do_not_matter(): void
    {
        $joao = Barber::create(['name' => 'Joao', 'email' => 'joao@barberflow.test']);
        $this->appointment('10:00:00', '10:30:00', barber: $joao);

        $this->slots()
            ->assertOk()
            ->assertJsonCount(20, 'data');

        $this->assertNotContains('10:00', $this->slots(barber: $joao)->json('data'));
    }

    public function test_today_hides_the_times_that_already_passed(): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' 12:10:00'));

        $this->slots()
            ->assertOk()
            ->assertExactJson(['data' => $this->times('12:30', '18:30')]);

        // Na hora exata, o horário já não vale.
        $this->travelTo(Carbon::parse(self::MONDAY.' 12:30:00'));

        $this->slots()->assertJsonPath('data.0', '13:00');

        // Depois do último início possível, a lista fica vazia.
        $this->travelTo(Carbon::parse(self::MONDAY.' 18:45:00'));

        $this->slots()->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_other_days_keep_every_time(): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' 12:10:00'));

        $this->slots(['date' => '2026-11-17'])
            ->assertOk()
            ->assertJsonCount(20, 'data');
    }

    public static function daysWithoutSlots(): array
    {
        return [
            'domingo' => ['2026-11-22'],
            'dia que já passou' => ['2026-11-14'],
            // Hoje mais 60 dias (o limite padrão) é a sexta 15/01/2027.
            'sábado depois do limite de dias' => ['2027-01-16'],
        ];
    }

    public function test_the_last_day_of_the_limit_keeps_every_time(): void
    {
        $this->slots(['date' => '2027-01-15'])
            ->assertOk()
            ->assertJsonCount(20, 'data');
    }

    #[DataProvider('daysWithoutSlots')]
    public function test_days_without_slots_return_an_empty_list(string $date): void
    {
        $this->slots(['date' => $date])
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_grid_comes_from_the_config(): void
    {
        config(['barberflow.business' => ['opening' => '10:00', 'closing' => '12:00', 'slot_minutes' => 20, 'days' => [1]]]);

        // O de 11:40 terminaria às 12:10, depois do fechamento.
        $this->slots()
            ->assertOk()
            ->assertExactJson(['data' => ['10:00', '10:20', '10:40', '11:00', '11:20']]);

        $this->slots(['date' => '2026-11-17'])->assertExactJson(['data' => []]);
    }

    public function test_every_listed_slot_can_be_booked(): void
    {
        $this->appointment('10:00:00', '10:30:00');

        foreach ($this->slots()->json('data') as $index => $start) {
            $client = Client::create(['name' => "Cliente {$index}", 'email' => "cliente{$index}@barberflow.test"]);

            $this->postJson('/api/appointments', [
                'client_id' => $client->id,
                'barber_id' => $this->barber->id,
                'service_id' => $this->service->id,
                'date' => self::MONDAY,
                'start_time' => $start,
            ])->assertCreated();
        }

        // Agenda cheia: nenhum horário livre.
        $this->slots()->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_rescheduling_counts_the_own_time_as_free(): void
    {
        $own = $this->appointment('10:00:00', '10:30:00');
        $this->appointment('11:00:00', '11:30:00');

        $this->assertNotContains('10:00', $this->slots()->json('data'));

        $data = $this->slots(['appointment_id' => $own->id])->assertOk()->json('data');

        // O horário dele volta para a lista; o do outro agendamento continua ocupado.
        $this->assertContains('10:00', $data);
        $this->assertNotContains('11:00', $data);
        $this->assertCount(19, $data);
    }

    public function test_rescheduling_lists_the_times_that_only_cross_the_own_appointment(): void
    {
        $own = $this->appointment('10:00:00', '10:30:00');
        $this->appointment('11:00:00', '11:30:00');
        $hour = Service::create(['name' => 'Corte e barba', 'duration' => 60, 'price' => 60]);

        $data = $this->slots(['service_id' => $hour->id, 'appointment_id' => $own->id])->assertOk()->json('data');

        // 09:30 às 10:30 passa por cima só do próprio horário; 10:30 às 11:30 cruza o outro agendamento.
        $this->assertContains('09:30', $data);
        $this->assertContains('10:00', $data);
        $this->assertNotContains('10:30', $data);

        // E a remarcação aceita o horário listado.
        $this->patchJson("/api/appointments/{$own->id}", ['service_id' => $hour->id, 'start_time' => '09:30'])
            ->assertOk()
            ->assertJsonPath('start_time', '09:30:00')
            ->assertJsonPath('end_time', '10:30:00');
    }

    public function test_an_appointment_of_another_barber_changes_nothing(): void
    {
        $joao = Barber::create(['name' => 'Joao', 'email' => 'joao@barberflow.test']);
        $this->appointment('10:00:00', '10:30:00');
        $other = $this->appointment('11:00:00', '11:30:00', barber: $joao);

        $this->assertSame(
            $this->slots()->json('data'),
            $this->slots(['appointment_id' => $other->id])->assertOk()->json('data'),
        );
    }

    public function test_requires_an_existing_appointment_to_reschedule(): void
    {
        $this->slots(['appointment_id' => 999])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment_id.0', 'O valor escolhido no campo agendamento é inválido.');

        $this->slots(['appointment_id' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_deleted_barber_returns_404(): void
    {
        $this->barber->releaseEmailAndDelete();

        $this->slots()
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }

    public function test_requires_a_valid_date_and_service(): void
    {
        $this->getJson("/api/barbers/{$this->barber->id}/available-slots")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'service_id'])
            ->assertJsonPath('errors.date.0', 'O campo data é obrigatório.');

        $this->slots(['date' => '16/11/2026'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->slots(['service_id' => 999])->assertUnprocessable()->assertJsonValidationErrors(['service_id']);
    }

    public function test_inactive_or_deleted_service_is_refused(): void
    {
        $this->service->update(['status' => 'inactive']);

        $this->slots()
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', 'Este serviço está inativo e não pode ser agendado.');

        $this->service->delete();

        $this->slots()
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', 'Este serviço foi excluído.');
    }
}
