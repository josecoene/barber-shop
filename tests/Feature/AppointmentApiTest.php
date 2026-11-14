<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Services\AppointmentScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Regras da agenda, testadas como admin, que pode tudo. As permissões de cada
 * perfil ficam no AppointmentAuthorizationTest e os horários livres no
 * AvailableSlotsTest.
 *
 * O "agora" fica fixo na segunda-feira 16/11/2026 às 08:00 (antes da abertura),
 * no fuso America/Sao_Paulo. O expediente é o padrão: 09:00 às 19:00, de
 * segunda a sábado, em fatias de 30 minutos.
 */
class AppointmentApiTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private const SATURDAY_BEFORE = '2026-11-14';

    private const SUNDAY = '2026-11-22';

    /**
     * Hoje (16/11/2026) mais os 60 dias do limite padrão: uma sexta.
     */
    private const LAST_BOOKABLE_DAY = '2027-01-15';

    /**
     * O sábado depois do limite: dia de expediente, mas longe demais.
     */
    private const AFTER_THE_LIMIT = '2027-01-16';

    private const HAS_UPCOMING = ['message' => 'Este cadastro tem agendamentos futuros. Cancele ou remarque antes de excluir.'];

    /**
     * Para onde cada status pode ir. Escrita aqui à mão, para conferir o
     * AppointmentStatus::transitions() contra a regra combinada.
     */
    private const VALID_TRANSITIONS = [
        'scheduled' => ['confirmed', 'in_progress', 'cancelled', 'no_show'],
        'confirmed' => ['in_progress', 'cancelled', 'no_show'],
        'in_progress' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    private Client $client;

    private Barber $barber;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));
        $this->actingAsAdmin();

        $this->client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => self::MONDAY,
            'start_time' => '10:00',
        ], $overrides);
    }

    /**
     * Agendamento gravado direto no banco, sem passar pelas regras: segunda às
     * 10:00, 30 minutos, Ana com o Pedro.
     */
    private function appointment(array $overrides = []): Appointment
    {
        return Appointment::create(array_merge([
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => self::MONDAY,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'price' => 35,
        ], $overrides));
    }

    private function newClient(string $name): Client
    {
        return Client::create(['name' => $name, 'email' => strtolower($name).'@barberflow.test']);
    }

    private function newBarber(string $name): Barber
    {
        return Barber::create(['name' => $name, 'email' => strtolower($name).'@barberflow.test']);
    }

    private function newService(int $duration, float $price = 50): Service
    {
        return Service::create(['name' => "Serviço de {$duration} minutos", 'duration' => $duration, 'price' => $price]);
    }

    public function test_creates_an_appointment_with_the_end_calculated_by_the_server(): void
    {
        // Término, preço e status enviados são ignorados: quem calcula é o servidor.
        $response = $this->postJson('/api/appointments', $this->payload([
            'end_time' => '18:00',
            'price' => 1,
            'status' => 'completed',
            'notes' => 'Primeira vez na barbearia',
        ]))
            ->assertCreated()
            ->assertJsonPath('client_id', $this->client->id)
            ->assertJsonPath('barber_id', $this->barber->id)
            ->assertJsonPath('service_id', $this->service->id)
            ->assertJsonPath('date', self::MONDAY)
            ->assertJsonPath('start_time', '10:00:00')
            ->assertJsonPath('end_time', '10:30:00')
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('status_label', 'Agendado')
            ->assertJsonPath('price', '35.00')
            ->assertJsonPath('notes', 'Primeira vez na barbearia')
            ->assertJsonPath('client', ['id' => $this->client->id, 'name' => 'Ana'])
            ->assertJsonPath('barber', ['id' => $this->barber->id, 'name' => 'Pedro'])
            ->assertJsonPath('service', ['id' => $this->service->id, 'name' => 'Corte']);

        $this->assertDatabaseHas('appointments', [
            'id' => $response->json('id'),
            'date' => self::MONDAY,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'scheduled',
        ]);
    }

    public function test_end_follows_the_duration_of_the_service(): void
    {
        $service = $this->newService(45);

        $this->postJson('/api/appointments', $this->payload(['service_id' => $service->id, 'start_time' => '09:30']))
            ->assertCreated()
            ->assertJsonPath('start_time', '09:30:00')
            ->assertJsonPath('end_time', '10:15:00');
    }

    public function test_model_always_stores_the_date_and_the_times_in_the_database_format(): void
    {
        // Outro código (seeders, lembretes) pode passar um Carbon e H:i: o banco fica com Y-m-d e H:i:s.
        $appointment = $this->appointment([
            'date' => Carbon::parse(self::TUESDAY.' 15:00:00'),
            'start_time' => '15:00',
            'end_time' => '15:30',
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'date' => self::TUESDAY,
            'start_time' => '15:00:00',
            'end_time' => '15:30:00',
        ]);

        $this->getJson('/api/appointments?date='.self::TUESDAY)
            ->assertOk()
            ->assertJsonPath('data.0.id', $appointment->id)
            ->assertJsonPath('data.0.date', self::TUESDAY);
    }

    public function test_accepts_the_start_time_with_seconds(): void
    {
        $this->postJson('/api/appointments', $this->payload(['start_time' => '10:30:00']))
            ->assertCreated()
            ->assertJsonPath('start_time', '10:30:00')
            ->assertJsonPath('end_time', '11:00:00');
    }

    public function test_rejects_missing_fields(): void
    {
        $this->postJson('/api/appointments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id', 'barber_id', 'service_id', 'date', 'start_time'])
            ->assertJsonMissingValidationErrors(['notes'])
            ->assertJsonPath('errors.client_id.0', 'O campo cliente é obrigatório.')
            ->assertJsonPath('errors.date.0', 'O campo data é obrigatório.')
            ->assertJsonPath('errors.start_time.0', 'O campo horário é obrigatório.');
    }

    public static function invalidFields(): array
    {
        return [
            'data em outro formato' => ['date', '16/11/2026'],
            'data que não existe' => ['date', '2026-02-30'],
            'horário em outro formato' => ['start_time', '10h'],
            'horário que não existe' => ['start_time', '25:00'],
            'cliente que não existe' => ['client_id', 999],
            'barbeiro que não existe' => ['barber_id', 999],
            'serviço que não existe' => ['service_id', 999],
            'cliente que não é número' => ['client_id', 'abc'],
            'observações com mais de 1000 caracteres' => ['notes', str_repeat('a', 1001)],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_fields(string $field, mixed $value): void
    {
        $this->postJson('/api/appointments', $this->payload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, Appointment::count());
    }

    public function test_accepts_notes_with_1000_characters(): void
    {
        $this->postJson('/api/appointments', $this->payload(['notes' => str_repeat('a', 1000)]))
            ->assertCreated();
    }

    public static function overlaps(): array
    {
        // Agendamento existente das 10:00 às 11:00.
        return [
            'mesmo início' => ['10:00', 30],
            'começa no meio' => ['10:30', 30],
            'termina no meio' => ['09:30', 60],
            'cobre o outro inteiro' => ['09:30', 120],
        ];
    }

    #[DataProvider('overlaps')]
    public function test_rejects_overlap_in_the_barber_agenda(string $start, int $duration): void
    {
        $this->appointment(['start_time' => '10:00:00', 'end_time' => '11:00:00']);

        $this->postJson('/api/appointments', $this->payload([
            'client_id' => $this->newClient('Bruno')->id,
            'service_id' => $this->newService($duration)->id,
            'start_time' => $start,
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Horário indisponível para este barbeiro.')
            ->assertJsonMissingValidationErrors(['client_id']);

        $this->assertSame(1, Appointment::count());
    }

    public function test_allows_appointments_back_to_back(): void
    {
        $this->appointment(['start_time' => '10:00:00', 'end_time' => '10:30:00']);

        // Mesmo barbeiro e mesmo cliente: um termina quando o outro começa.
        $this->postJson('/api/appointments', $this->payload(['start_time' => '09:30']))
            ->assertCreated()
            ->assertJsonPath('end_time', '10:00:00');

        $this->postJson('/api/appointments', $this->payload(['start_time' => '10:30']))
            ->assertCreated()
            ->assertJsonPath('start_time', '10:30:00');

        $this->assertSame(3, Appointment::count());
    }

    public static function statusesThatTakeTheTime(): array
    {
        return [
            'agendado' => ['scheduled'],
            'confirmado' => ['confirmed'],
            'em atendimento' => ['in_progress'],
            'concluído' => ['completed'],
        ];
    }

    #[DataProvider('statusesThatTakeTheTime')]
    public function test_every_status_but_cancelled_and_no_show_takes_the_time(string $status): void
    {
        $this->appointment(['status' => $status]);

        $this->postJson('/api/appointments', $this->payload(['client_id' => $this->newClient('Bruno')->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);
    }

    public function test_cancelled_and_no_show_free_the_time(): void
    {
        $this->appointment(['status' => 'cancelled']);
        $this->appointment(['status' => 'no_show']);

        $this->postJson('/api/appointments', $this->payload())
            ->assertCreated();
    }

    public function test_another_barber_can_take_the_same_time(): void
    {
        $this->appointment();

        $this->postJson('/api/appointments', $this->payload([
            'barber_id' => $this->newBarber('Joao')->id,
            'client_id' => $this->newClient('Bruno')->id,
        ]))->assertCreated();
    }

    public function test_client_cannot_be_in_two_places_at_the_same_time(): void
    {
        $this->appointment(['start_time' => '10:00:00', 'end_time' => '11:00:00']);

        $this->postJson('/api/appointments', $this->payload([
            'barber_id' => $this->newBarber('Joao')->id,
            'start_time' => '10:30',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.client_id.0', 'O cliente já tem um horário marcado nesse período.')
            ->assertJsonMissingValidationErrors(['start_time']);

        $this->assertSame(1, Appointment::count());
    }

    public function test_reports_the_barber_and_the_client_conflicts_together(): void
    {
        $this->appointment();

        $this->postJson('/api/appointments', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Horário indisponível para este barbeiro.')
            ->assertJsonPath('errors.client_id.0', 'O cliente já tem um horário marcado nesse período.');
    }

    public function test_rejects_a_date_that_already_passed(): void
    {
        // Sábado é dia de expediente, mas este já passou.
        $this->postJson('/api/appointments', $this->payload(['date' => self::SATURDAY_BEFORE]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Essa data já passou.');
    }

    public function test_rejects_a_time_that_already_passed_today(): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' 12:10:00'));

        $this->postJson('/api/appointments', $this->payload(['start_time' => '12:00']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Esse horário já passou.');

        $this->postJson('/api/appointments', $this->payload(['start_time' => '12:30']))
            ->assertCreated();

        // Na hora exata do início, o horário já conta como passado.
        $this->travelTo(Carbon::parse(self::MONDAY.' 13:00:00'));

        $this->postJson('/api/appointments', $this->payload(['start_time' => '13:00']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Esse horário já passou.');
    }

    public static function outsideBusinessHours(): array
    {
        return [
            'antes da abertura' => ['08:30', 30],
            'na hora do fechamento' => ['19:00', 30],
            'depois do fechamento' => ['20:00', 30],
            'termina depois do fechamento' => ['18:30', 60],
        ];
    }

    #[DataProvider('outsideBusinessHours')]
    public function test_rejects_appointments_outside_business_hours(string $start, int $duration): void
    {
        $this->postJson('/api/appointments', $this->payload([
            'service_id' => $this->newService($duration)->id,
            'start_time' => $start,
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'O atendimento precisa começar e terminar dentro do expediente, das 09:00 às 19:00.');
    }

    public function test_accepts_the_first_and_the_last_time_of_the_day(): void
    {
        $this->postJson('/api/appointments', $this->payload(['start_time' => '09:00']))
            ->assertCreated();

        // Termina exatamente no fechamento.
        $this->postJson('/api/appointments', $this->payload(['start_time' => '18:30']))
            ->assertCreated()
            ->assertJsonPath('end_time', '19:00:00');
    }

    public static function startsOutsideTheSlots(): array
    {
        return [
            'quarto de hora' => ['10:15'],
            'um minuto depois' => ['10:01'],
            'com segundos' => ['10:00:30'],
        ];
    }

    #[DataProvider('startsOutsideTheSlots')]
    public function test_rejects_a_start_outside_the_slots(string $start): void
    {
        $this->postJson('/api/appointments', $this->payload(['start_time' => $start]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Escolha um horário de 30 em 30 minutos, a partir das 09:00.');
    }

    public function test_rejects_sunday(): void
    {
        $this->postJson('/api/appointments', $this->payload(['date' => self::SUNDAY]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'A barbearia não abre nesse dia.');
    }

    public function test_books_up_to_the_limit_of_days_ahead(): void
    {
        // Hoje mais 60 dias (o padrão) é a sexta 15/01/2027; o sábado seguinte já passa do limite.
        $this->postJson('/api/appointments', $this->payload(['date' => self::LAST_BOOKABLE_DAY]))
            ->assertCreated();

        $this->postJson('/api/appointments', $this->payload(['date' => self::AFTER_THE_LIMIT, 'start_time' => '11:00']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Só é possível agendar com até 60 dias de antecedência (até 15/01/2027).');

        // A remarcação também não passa do limite.
        $appointment = $this->appointment();

        $this->patchJson("/api/appointments/{$appointment->id}", ['date' => self::AFTER_THE_LIMIT])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Só é possível agendar com até 60 dias de antecedência (até 15/01/2027).');

        $this->assertSame(self::MONDAY, $appointment->fresh()->date->format('Y-m-d'));
    }

    public function test_limit_of_days_ahead_comes_from_the_config(): void
    {
        config(['barberflow.booking_days_ahead' => 1]);

        $this->postJson('/api/appointments', $this->payload(['date' => self::TUESDAY]))
            ->assertCreated();

        $this->postJson('/api/appointments', $this->payload(['date' => '2026-11-18']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'Só é possível agendar com até 1 dia de antecedência (até 17/11/2026).');
    }

    public function test_business_hours_come_from_the_config(): void
    {
        config(['barberflow.business' => ['opening' => '10:00', 'closing' => '12:00', 'slot_minutes' => 20, 'days' => [7]]]);

        // O domingo passa a abrir, das 10:00 às 12:00, em fatias de 20 minutos.
        $this->postJson('/api/appointments', $this->payload(['date' => self::SUNDAY, 'start_time' => '10:40']))
            ->assertCreated();

        $this->postJson('/api/appointments', $this->payload(['date' => self::SUNDAY, 'start_time' => '10:30']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Escolha um horário de 20 em 20 minutos, a partir das 10:00.');

        $this->postJson('/api/appointments', $this->payload(['date' => self::SUNDAY, 'start_time' => '11:40']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'O atendimento precisa começar e terminar dentro do expediente, das 10:00 às 12:00.');

        // E a segunda deixa de ser dia de expediente.
        $this->postJson('/api/appointments', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'A barbearia não abre nesse dia.');
    }

    public function test_rejects_an_inactive_service(): void
    {
        $this->service->update(['status' => 'inactive']);

        $this->postJson('/api/appointments', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', 'Este serviço está inativo e não pode ser agendado.');
    }

    public function test_rejects_deleted_client_barber_and_service(): void
    {
        $this->client->anonymizeAndDelete();
        $this->barber->releaseEmailAndDelete();
        $this->service->delete();

        $this->postJson('/api/appointments', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.client_id.0', 'Este cliente foi excluído.')
            ->assertJsonPath('errors.barber_id.0', 'Este barbeiro foi excluído.')
            ->assertJsonPath('errors.service_id.0', 'Este serviço foi excluído.');

        $this->assertSame(0, Appointment::count());
    }

    public function test_copies_the_price_and_keeps_it_when_the_service_price_changes(): void
    {
        $id = $this->postJson('/api/appointments', $this->payload())
            ->assertCreated()
            ->assertJsonPath('price', '35.00')
            ->json('id');

        $this->putJson("/api/services/{$this->service->id}", ['price' => 50])->assertOk();

        $this->getJson("/api/appointments/{$id}")
            ->assertOk()
            ->assertJsonPath('price', '35.00');
    }

    public function test_shows_an_appointment(): void
    {
        $appointment = $this->appointment(['notes' => 'Prefere máquina 2']);

        $this->getJson("/api/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('id', $appointment->id)
            ->assertJsonPath('date', self::MONDAY)
            ->assertJsonPath('notes', 'Prefere máquina 2')
            ->assertJsonPath('client', ['id' => $this->client->id, 'name' => 'Ana']);
    }

    public function test_returns_404_for_unknown_appointment(): void
    {
        $this->getJson('/api/appointments/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }

    public function test_appointments_are_not_deleted_cancelling_is_a_status(): void
    {
        $appointment = $this->appointment();

        $this->deleteJson("/api/appointments/{$appointment->id}")
            ->assertStatus(405)
            ->assertExactJson(['message' => 'Método não permitido para esta rota.']);

        $this->assertModelExists($appointment);
    }

    public function test_reschedules_ignoring_the_appointment_itself(): void
    {
        $hour = $this->newService(60, 60);
        $appointment = $this->appointment(['service_id' => $hour->id, 'end_time' => '11:00:00', 'price' => 60]);

        // 10:30 às 11:30 cruza o horário antigo dele mesmo, que não conta.
        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '10:30'])
            ->assertOk()
            ->assertJsonPath('start_time', '10:30:00')
            ->assertJsonPath('end_time', '11:30:00')
            ->assertJsonPath('price', '60.00');
    }

    public function test_reschedules_to_another_day_and_barber(): void
    {
        $appointment = $this->appointment();
        $joao = $this->newBarber('Joao');

        $this->putJson("/api/appointments/{$appointment->id}", [
            'date' => self::TUESDAY,
            'start_time' => '15:00',
            'barber_id' => $joao->id,
        ])
            ->assertOk()
            ->assertJsonPath('date', self::TUESDAY)
            ->assertJsonPath('start_time', '15:00:00')
            ->assertJsonPath('end_time', '15:30:00')
            ->assertJsonPath('barber', ['id' => $joao->id, 'name' => 'Joao']);

        $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'date' => self::TUESDAY, 'barber_id' => $joao->id]);
    }

    public function test_changing_the_service_recalculates_the_end_and_the_price(): void
    {
        $appointment = $this->appointment();
        $hour = Service::create(['name' => 'Corte e barba', 'duration' => 60, 'price' => 60]);

        $this->patchJson("/api/appointments/{$appointment->id}", ['service_id' => $hour->id])
            ->assertOk()
            ->assertJsonPath('start_time', '10:00:00')
            ->assertJsonPath('end_time', '11:00:00')
            ->assertJsonPath('price', '60.00')
            ->assertJsonPath('service', ['id' => $hour->id, 'name' => 'Corte e barba']);
    }

    public function test_moving_the_time_keeps_the_price_of_the_booking(): void
    {
        $appointment = $this->appointment();
        $this->service->update(['price' => 50]);

        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '11:00'])
            ->assertOk()
            ->assertJsonPath('end_time', '11:30:00')
            ->assertJsonPath('price', '35.00');
    }

    public function test_reschedule_runs_the_rules_again(): void
    {
        $appointment = $this->appointment();
        $this->appointment(['client_id' => $this->newClient('Bruno')->id, 'start_time' => '11:00:00', 'end_time' => '11:30:00']);

        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '11:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Horário indisponível para este barbeiro.');

        $this->patchJson("/api/appointments/{$appointment->id}", ['date' => self::SUNDAY])
            ->assertUnprocessable()
            ->assertJsonPath('errors.date.0', 'A barbearia não abre nesse dia.');

        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '10:15'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time']);

        $this->service->update(['status' => 'inactive']);

        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '12:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', 'Este serviço está inativo e não pode ser agendado.');

        $this->assertSame('10:00:00', $appointment->fresh()->start_time);
    }

    public function test_reschedule_rejects_invalid_fields(): void
    {
        $appointment = $this->appointment();

        $this->patchJson("/api/appointments/{$appointment->id}", [
            'date' => null,
            'start_time' => '10h',
            'barber_id' => 999,
            'notes' => str_repeat('a', 1001),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'start_time', 'barber_id', 'notes']);
    }

    public function test_update_does_not_change_the_client_or_the_status(): void
    {
        $appointment = $this->appointment();
        $bruno = $this->newClient('Bruno');

        $this->patchJson("/api/appointments/{$appointment->id}", [
            'client_id' => $bruno->id,
            'status' => 'completed',
            'price' => 1,
            'notes' => 'Trocar a lâmina',
        ])
            ->assertOk()
            ->assertJsonPath('client_id', $this->client->id)
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('price', '35.00')
            ->assertJsonPath('notes', 'Trocar a lâmina');
    }

    public function test_confirmed_appointment_can_be_rescheduled(): void
    {
        $appointment = $this->appointment(['status' => 'confirmed']);

        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '11:00'])
            ->assertOk()
            ->assertJsonPath('start_time', '11:00:00')
            ->assertJsonPath('status', 'confirmed');
    }

    public static function statusesThatCannotBeRescheduled(): array
    {
        return [
            'em atendimento' => ['in_progress'],
            'concluído' => ['completed'],
            'cancelado' => ['cancelled'],
            'faltou' => ['no_show'],
        ];
    }

    #[DataProvider('statusesThatCannotBeRescheduled')]
    public function test_reschedule_is_refused_once_the_appointment_started_or_ended(string $status): void
    {
        $appointment = $this->appointment(['status' => $status]);

        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '11:00'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Só é possível remarcar um agendamento com status Agendado ou Confirmado.');

        // Nem as observações mudam.
        $this->patchJson("/api/appointments/{$appointment->id}", ['notes' => 'Depois do atendimento'])
            ->assertUnprocessable();

        $this->assertSame('10:00:00', $appointment->fresh()->start_time);
        $this->assertNull($appointment->fresh()->notes);
    }

    public function test_notes_change_without_running_the_time_rules(): void
    {
        $appointment = $this->appointment();

        // O início (10:00) já passou e o atendimento ainda não começou.
        $this->travelTo(Carbon::parse(self::MONDAY.' 10:05:00'));

        $this->patchJson("/api/appointments/{$appointment->id}", ['notes' => 'Avisou que vai atrasar', 'start_time' => '10:00'])
            ->assertOk()
            ->assertJsonPath('notes', 'Avisou que vai atrasar')
            ->assertJsonPath('start_time', '10:00:00');

        // Mudar o horário refaz as regras, e o passado continua recusado.
        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '09:30'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.start_time.0', 'Esse horário já passou.');

        $this->patchJson("/api/appointments/{$appointment->id}", ['notes' => null])
            ->assertOk()
            ->assertJsonPath('notes', null);
    }

    public function test_reschedule_refuses_a_copy_changed_by_someone_else_meanwhile(): void
    {
        $appointment = $this->appointment();
        $stale = Appointment::find($appointment->id);

        // Outro pedido passou o agendamento para outro barbeiro depois da leitura.
        $appointment->update(['barber_id' => $this->newBarber('Joao')->id]);

        try {
            app(AppointmentScheduler::class)->reschedule($stale, ['start_time' => '11:00']);
            $this->fail('A remarcação devia recusar a cópia desatualizada.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('O agendamento mudou enquanto você editava. Recarregue e tente de novo.', $e->getMessage());
        }

        $this->assertSame('10:00:00', $appointment->fresh()->start_time);
    }

    public function test_status_change_reads_the_current_status_again(): void
    {
        $appointment = $this->appointment();
        $stale = Appointment::find($appointment->id);

        // Outro pedido cancelou depois da leitura: confirmar agora parte de cancelado.
        $appointment->update(['status' => 'cancelled']);

        try {
            app(AppointmentScheduler::class)->changeStatus($stale, AppointmentStatus::Confirmed);
            $this->fail('A mudança de status devia partir do status atual.');
        } catch (ValidationException $e) {
            $this->assertSame(['status' => ['Não é possível mudar de Cancelado para Confirmado.']], $e->errors());
        }

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    public static function transitions(): array
    {
        $cases = [];

        foreach (self::VALID_TRANSITIONS as $from => $allowed) {
            foreach (array_keys(self::VALID_TRANSITIONS) as $to) {
                $cases["{$from} para {$to}"] = [$from, $to, in_array($to, $allowed, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_status_changes_only_through_the_allowed_transitions(string $from, string $to, bool $allowed): void
    {
        // Depois do início do horário, para a regra do horário não interferir.
        $this->travelTo(Carbon::parse(self::MONDAY.' 10:05:00'));
        $appointment = $this->appointment(['status' => $from]);

        $response = $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => $to]);

        if ($allowed) {
            $response->assertOk()
                ->assertJsonPath('status', $to)
                ->assertJsonPath('status_label', AppointmentStatus::from($to)->label());

            return;
        }

        $fromLabel = AppointmentStatus::from($from)->label();
        $toLabel = AppointmentStatus::from($to)->label();

        $response->assertUnprocessable()
            ->assertJsonPath('errors.status.0', "Não é possível mudar de {$fromLabel} para {$toLabel}.");

        $this->assertSame($from, $appointment->fresh()->status->value);
    }

    public function test_walks_the_whole_way_of_an_appointment(): void
    {
        $appointment = $this->appointment(['start_time' => '09:00:00', 'end_time' => '09:30:00']);

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('status_label', 'Confirmado')
            ->assertJsonPath('client', ['id' => $this->client->id, 'name' => 'Ana']);

        $this->travelTo(Carbon::parse(self::MONDAY.' 09:00:00'));

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('status_label', 'Em atendimento');

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('status_label', 'Concluído');

        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    public static function statusesThatNeedTheStart(): array
    {
        return [
            'em atendimento' => ['scheduled', 'in_progress', 'Em atendimento'],
            'faltou' => ['confirmed', 'no_show', 'Faltou'],
            'concluído' => ['in_progress', 'completed', 'Concluído'],
        ];
    }

    #[DataProvider('statusesThatNeedTheStart')]
    public function test_statuses_that_need_the_start_are_refused_before_it(string $from, string $to, string $label): void
    {
        // Agora são 08:00 e o horário começa às 10:00.
        $appointment = $this->appointment(['status' => $from]);

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => $to])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', "Só é possível mudar para {$label} depois do horário de início.");

        $this->assertSame($from, $appointment->fresh()->status->value);

        // Na hora do início, já vale.
        $this->travelTo(Carbon::parse(self::MONDAY.' 10:00:00'));

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => $to])
            ->assertOk()
            ->assertJsonPath('status', $to);
    }

    public function test_rejects_an_unknown_or_missing_status(): void
    {
        $appointment = $this->appointment();

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'foo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/appointments/{$appointment->id}/status", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'O campo status é obrigatório.');
    }

    public function test_cancelling_frees_the_time(): void
    {
        $appointment = $this->appointment();

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status_label', 'Cancelado');

        $this->postJson('/api/appointments', $this->payload())
            ->assertCreated();
    }

    public function test_lists_ordered_by_date_and_time_with_pagination(): void
    {
        $this->appointment(['date' => self::TUESDAY, 'start_time' => '09:00:00', 'end_time' => '09:30:00']);
        $this->appointment(['start_time' => '11:00:00', 'end_time' => '11:30:00']);
        $this->appointment(['start_time' => '09:30:00', 'end_time' => '10:00:00']);

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.start_time', '09:30:00')
            ->assertJsonPath('data.1.start_time', '11:00:00')
            ->assertJsonPath('data.2.date', self::TUESDAY)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'client_id', 'barber_id', 'service_id', 'date', 'start_time', 'end_time',
                    'status', 'status_label', 'price', 'notes',
                    'client' => ['id', 'name'], 'barber' => ['id', 'name'], 'service' => ['id', 'name'],
                ]],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $response = $this->getJson('/api/appointments?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', self::TUESDAY)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);

        $this->assertStringContainsString('per_page=2', $response->json('links.prev'));
    }

    public function test_items_bring_only_the_id_and_the_name_of_client_barber_and_service(): void
    {
        $this->client->update(['phone' => '67911112222']);
        $this->appointment();

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.client', ['id' => $this->client->id, 'name' => 'Ana'])
            ->assertJsonPath('data.0.barber', ['id' => $this->barber->id, 'name' => 'Pedro'])
            ->assertJsonPath('data.0.service', ['id' => $this->service->id, 'name' => 'Corte']);
    }

    public function test_filters_by_date_period_barber_client_and_status(): void
    {
        $joao = $this->newBarber('Joao');
        $bruno = $this->newClient('Bruno');

        $monday = $this->appointment();
        $tuesday = $this->appointment(['date' => self::TUESDAY, 'barber_id' => $joao->id, 'status' => 'confirmed']);
        $wednesday = $this->appointment(['date' => '2026-11-18', 'client_id' => $bruno->id, 'status' => 'cancelled']);

        $this->assertListed('date='.self::TUESDAY, [$tuesday]);
        $this->assertListed('from='.self::TUESDAY, [$tuesday, $wednesday]);
        $this->assertListed('to='.self::TUESDAY, [$monday, $tuesday]);
        $this->assertListed('from='.self::TUESDAY.'&to='.self::TUESDAY, [$tuesday]);
        $this->assertListed("barber_id={$joao->id}", [$tuesday]);
        $this->assertListed("client_id={$bruno->id}", [$wednesday]);
        $this->assertListed('status=cancelled', [$wednesday]);
        $this->assertListed("barber_id={$this->barber->id}&status=scheduled", [$monday]);
        $this->assertListed('date=2026-11-19', []);
    }

    /**
     * @param  list<Appointment>  $expected
     */
    private function assertListed(string $query, array $expected): void
    {
        $this->assertSame(
            array_map(fn (Appointment $appointment) => $appointment->id, $expected),
            array_column($this->getJson("/api/appointments?{$query}")->assertOk()->json('data'), 'id'),
            $query,
        );
    }

    public static function invalidFilters(): array
    {
        return [
            'data em outro formato' => ['date=16/11/2026', 'date'],
            'início do período em outro formato' => ['from=ontem', 'from'],
            'fim antes do início' => ['from=2026-11-17&to=2026-11-16', 'to'],
            'status que não existe' => ['status=foo', 'status'],
            'barbeiro que não é número' => ['barber_id=abc', 'barber_id'],
            'cliente que não é número' => ['client_id=abc', 'client_id'],
            'mais de 100 por página' => ['per_page=101', 'per_page'],
            'página zero' => ['page=0', 'page'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_rejects_invalid_filters(string $query, string $field): void
    {
        $this->getJson("/api/appointments?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    public function test_period_message_is_in_portuguese(): void
    {
        $this->getJson('/api/appointments?from=2026-11-17&to=2026-11-16')
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', 'O campo data final deve ser uma data igual ou posterior a data inicial.');
    }

    public function test_lists_without_one_query_per_appointment(): void
    {
        $this->appointment();

        $queriesForOne = $this->countQueries(fn () => $this->getJson('/api/appointments')->assertJsonCount(1, 'data'));

        foreach (['Bruno', 'Carla', 'Diego'] as $index => $name) {
            $start = 11 + $index;

            $this->appointment([
                'client_id' => $this->newClient($name)->id,
                'barber_id' => $this->newBarber("Barbeiro{$name}")->id,
                'service_id' => $this->newService(30)->id,
                'start_time' => "{$start}:00:00",
                'end_time' => "{$start}:30:00",
            ]);
        }

        $queriesForFour = $this->countQueries(fn () => $this->getJson('/api/appointments')->assertJsonCount(4, 'data'));

        $this->assertSame($queriesForOne, $queriesForFour);
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

    public static function recordsOnTheAgenda(): array
    {
        return [
            'cliente' => ['clients', 'client'],
            'barbeiro' => ['barbers', 'barber'],
            'serviço' => ['services', 'service'],
        ];
    }

    #[DataProvider('recordsOnTheAgenda')]
    public function test_refuses_to_delete_a_record_with_upcoming_appointments(string $resource, string $record): void
    {
        $this->appointment(['date' => self::TUESDAY]);

        $this->deleteJson("/api/{$resource}/{$this->{$record}->id}")
            ->assertStatus(409)
            ->assertExactJson(self::HAS_UPCOMING);

        $this->assertNotSoftDeleted($this->{$record});
    }

    public static function activeStatuses(): array
    {
        return [
            'agendado' => ['scheduled'],
            'confirmado' => ['confirmed'],
            'em atendimento' => ['in_progress'],
        ];
    }

    #[DataProvider('activeStatuses')]
    public function test_an_active_appointment_today_also_blocks_the_delete(string $status): void
    {
        // Hoje, às 10:00: conta como futuro enquanto estiver ativo.
        $this->appointment(['status' => $status]);

        $this->deleteJson("/api/clients/{$this->client->id}")
            ->assertStatus(409)
            ->assertExactJson(self::HAS_UPCOMING);

        $this->assertSame('Ana', $this->client->fresh()->name);
    }

    #[DataProvider('recordsOnTheAgenda')]
    public function test_deletes_normally_with_only_past_or_inactive_appointments(string $resource, string $record): void
    {
        // Um passado que ficou como agendado e futuros que já não estão ativos.
        $this->appointment(['date' => self::SATURDAY_BEFORE]);
        $this->appointment(['date' => self::TUESDAY, 'status' => 'cancelled']);
        $this->appointment(['date' => self::TUESDAY, 'status' => 'no_show', 'start_time' => '11:00:00', 'end_time' => '11:30:00']);
        $this->appointment(['date' => self::TUESDAY, 'status' => 'completed', 'start_time' => '12:00:00', 'end_time' => '12:30:00']);

        $this->deleteJson("/api/{$resource}/{$this->{$record}->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($this->{$record});
    }

    public function test_history_keeps_showing_deleted_records(): void
    {
        $appointment = $this->appointment(['date' => self::SATURDAY_BEFORE, 'status' => 'completed']);

        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();
        $this->deleteJson("/api/barbers/{$this->barber->id}")->assertNoContent();
        $this->deleteJson("/api/services/{$this->service->id}")->assertNoContent();

        // O cliente aparece anonimizado; barbeiro e serviço mantêm o nome.
        $this->getJson("/api/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('client', ['id' => $this->client->id, 'name' => 'Cliente removido'])
            ->assertJsonPath('barber', ['id' => $this->barber->id, 'name' => 'Pedro'])
            ->assertJsonPath('service', ['id' => $this->service->id, 'name' => 'Corte']);

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.client.name', 'Cliente removido');
    }

    public function test_deleting_a_client_also_erases_the_notes_of_the_appointments(): void
    {
        // Observação é texto livre e pode ter dado pessoal: sai junto com o cadastro (LGPD).
        $own = $this->appointment(['date' => self::SATURDAY_BEFORE, 'status' => 'completed', 'notes' => 'Ana Souza, tel 67999990000, alergia a lâmina X']);
        $cancelled = $this->appointment(['date' => self::TUESDAY, 'status' => 'cancelled', 'notes' => 'Desmarcou pelo telefone 67999990000']);
        $other = $this->appointment(['client_id' => $this->newClient('Bruno')->id, 'date' => self::SATURDAY_BEFORE, 'notes' => 'Prefere máquina 2']);

        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();

        $this->getJson("/api/appointments/{$own->id}")
            ->assertOk()
            ->assertJsonPath('client', ['id' => $this->client->id, 'name' => 'Cliente removido'])
            ->assertJsonPath('notes', null);

        $this->assertNull($cancelled->fresh()->notes);

        // As observações dos outros clientes continuam.
        $this->assertSame('Prefere máquina 2', $other->fresh()->notes);
    }

    public function test_appointment_of_a_deleted_client_takes_no_new_notes(): void
    {
        // Um passado que ficou como agendado continua remarcável depois da exclusão, mas as observações
        // que ela apagou (LGPD) não voltam: um texto novo com dado pessoal ficaria ligado ao cadastro.
        $appointment = $this->appointment(['date' => self::SATURDAY_BEFORE, 'notes' => 'Ana Souza, tel 67999990000']);
        $this->deleteJson("/api/clients/{$this->client->id}")->assertNoContent();

        $this->putJson("/api/appointments/{$appointment->id}", ['notes' => 'Ana Souza, tel 67999990000'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.notes.0', 'O cliente foi excluído: deixe as observações em branco.');

        $this->assertNull($appointment->fresh()->notes);

        // Sem observações, o pedido passa.
        $this->putJson("/api/appointments/{$appointment->id}", ['notes' => null])
            ->assertOk()
            ->assertJsonPath('notes', null);
    }
}
