<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quem pode o quê na agenda: o barbeiro só na própria agenda e o cliente só nos
 * próprios horários, com até 3 agendamentos ativos e cancelando até 2 horas
 * antes. O 401 sem login de todas as rotas novas fica no AuthorizationTest.
 *
 * O "agora" fica fixo na segunda-feira 16/11/2026 às 08:00.
 */
class AppointmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const FORBIDDEN = ['message' => 'Você não tem permissão para esta ação.'];

    private const UNVERIFIED = ['message' => 'Confirme seu e-mail para continuar.'];

    private Barber $barber;

    private Barber $otherBarber;

    private Client $client;

    private Client $otherClient;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00:00'));

        $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test', 'phone' => '67988880000']);
        $this->otherBarber = Barber::create(['name' => 'Joao', 'email' => 'joao@barberflow.test']);
        $this->client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test', 'phone' => '67911112222']);
        $this->otherClient = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']);
        $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
    }

    /**
     * Agendamento gravado direto no banco, na segunda, com 30 minutos.
     */
    private function appointment(Barber $barber, Client $client, string $start = '10:00:00', string $status = 'scheduled'): Appointment
    {
        $end = Carbon::parse(self::MONDAY.' '.$start)->addMinutes(30)->format('H:i:s');

        return Appointment::create([
            'client_id' => $client->id,
            'barber_id' => $barber->id,
            'service_id' => $this->service->id,
            'date' => self::MONDAY,
            'start_time' => $start,
            'end_time' => $end,
            'status' => $status,
            'price' => 35,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'service_id' => $this->service->id,
            'date' => self::MONDAY,
            'start_time' => '10:00',
        ], $overrides);
    }

    private function slotsUri(): string
    {
        return "/api/barbers/{$this->barber->id}/available-slots?date=".self::MONDAY."&service_id={$this->service->id}";
    }

    public function test_barber_sees_only_the_own_agenda(): void
    {
        $own = $this->appointment($this->barber, $this->client);
        $other = $this->appointment($this->otherBarber, $this->otherClient);
        $this->actingAsBarber($this->barber);

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        // O filtro não abre a agenda de outro barbeiro.
        $this->getJson("/api/appointments?barber_id={$this->otherBarber->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/appointments/{$own->id}")->assertOk();

        $this->getJson("/api/appointments/{$other->id}")->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/appointments/{$other->id}", ['start_time' => '11:00'])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/appointments/{$other->id}/status", ['status' => 'cancelled'])->assertForbidden()->assertExactJson(self::FORBIDDEN);

        $this->assertSame('10:00:00', $other->fresh()->start_time);
        $this->assertSame('scheduled', $other->fresh()->status->value);
    }

    public function test_barber_books_and_manages_the_own_agenda(): void
    {
        $this->actingAsBarber($this->barber);

        // Sem barber_id, vale o do login.
        $id = $this->postJson('/api/appointments', $this->payload(['client_id' => $this->client->id]))
            ->assertCreated()
            ->assertJsonPath('barber_id', $this->barber->id)
            ->json('id');

        $this->postJson('/api/appointments', $this->payload([
            'client_id' => $this->otherClient->id,
            'barber_id' => $this->barber->id,
            'start_time' => '11:00',
        ]))->assertCreated();

        $this->patchJson("/api/appointments/{$id}", ['start_time' => '12:00'])
            ->assertOk()
            ->assertJsonPath('start_time', '12:00:00');

        $this->patchJson("/api/appointments/{$id}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');

        // A janela de 2 horas é só do cliente: a barbearia cancela em cima da hora.
        $this->travelTo(Carbon::parse(self::MONDAY.' 11:50:00'));

        $this->patchJson("/api/appointments/{$id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_barber_cannot_book_in_the_agenda_of_another_barber(): void
    {
        $this->actingAsBarber($this->barber);

        $this->postJson('/api/appointments', $this->payload([
            'client_id' => $this->client->id,
            'barber_id' => $this->otherBarber->id,
        ]))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);

        $this->assertSame(0, Appointment::count());
    }

    public function test_barber_cannot_move_an_appointment_to_another_barber(): void
    {
        $own = $this->appointment($this->barber, $this->client);
        $this->actingAsBarber($this->barber);

        $this->patchJson("/api/appointments/{$own->id}", ['barber_id' => $this->otherBarber->id])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);

        $this->assertSame($this->barber->id, $own->fresh()->barber_id);
    }

    public function test_client_books_for_himself(): void
    {
        $this->actingAsClient($this->client);

        // Sem client_id, vale o do login. O barbeiro aparece só com id e nome.
        $this->postJson('/api/appointments', $this->payload(['barber_id' => $this->barber->id]))
            ->assertCreated()
            ->assertJsonPath('client_id', $this->client->id)
            ->assertJsonPath('barber', ['id' => $this->barber->id, 'name' => 'Pedro']);

        $this->postJson('/api/appointments', $this->payload([
            'barber_id' => $this->barber->id,
            'client_id' => $this->client->id,
            'start_time' => '11:00',
        ]))->assertCreated();

        $this->assertSame(2, $this->client->appointments()->count());
    }

    public function test_client_books_up_to_the_limit_of_active_appointments(): void
    {
        $this->actingAsClient($this->client);

        // O limite padrão é 3 agendamentos ativos de hoje em diante.
        foreach (['10:00', '11:00', '12:00'] as $start) {
            $this->postJson('/api/appointments', $this->payload(['barber_id' => $this->barber->id, 'start_time' => $start]))
                ->assertCreated();
        }

        $this->postJson('/api/appointments', $this->payload(['barber_id' => $this->barber->id, 'start_time' => '13:00']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.client_id.0', 'Você já tem 3 horários marcados, o máximo para agendar pela sua conta. Cancele um horário para agendar outro, ou fale com a barbearia.');

        $this->assertSame(3, $this->client->appointments()->count());

        // Cancelou um, abre a vaga.
        $first = $this->client->appointments()->orderBy('start_time')->first();
        $this->patchJson("/api/appointments/{$first->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->postJson('/api/appointments', $this->payload(['barber_id' => $this->barber->id, 'start_time' => '13:00']))
            ->assertCreated();
    }

    public function test_past_and_finished_appointments_do_not_count_for_the_client_limit(): void
    {
        // Um de sábado passado que ficou como agendado, e os de hoje que já saíram da agenda.
        Appointment::create([
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => '2026-11-14',
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'price' => 35,
        ]);
        $this->appointment($this->otherBarber, $this->client, '15:00:00', 'cancelled');
        $this->appointment($this->otherBarber, $this->client, '16:00:00', 'no_show');
        $this->appointment($this->otherBarber, $this->client, '17:00:00', 'completed');
        $this->actingAsClient($this->client);

        foreach (['10:00', '11:00', '12:00'] as $start) {
            $this->postJson('/api/appointments', $this->payload(['barber_id' => $this->barber->id, 'start_time' => $start]))
                ->assertCreated();
        }
    }

    public function test_client_limit_comes_from_the_config(): void
    {
        config(['barberflow.client_max_active_appointments' => 1]);
        $this->appointment($this->barber, $this->client);
        $this->actingAsClient($this->client);

        $this->postJson('/api/appointments', $this->payload(['barber_id' => $this->barber->id, 'start_time' => '11:00']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.client_id.0', 'Você já tem 1 horário marcado, o máximo para agendar pela sua conta. Cancele um horário para agendar outro, ou fale com a barbearia.');
    }

    public function test_the_barbershop_books_for_the_client_without_the_limit(): void
    {
        foreach (['10:00:00', '11:00:00', '12:00:00'] as $start) {
            $this->appointment($this->otherBarber, $this->client, $start);
        }

        $this->actingAsBarber($this->barber);

        $this->postJson('/api/appointments', $this->payload(['client_id' => $this->client->id, 'start_time' => '13:00']))
            ->assertCreated();

        $this->actingAsAdmin();

        $this->postJson('/api/appointments', $this->payload([
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'start_time' => '14:00',
        ]))->assertCreated();

        $this->assertSame(5, $this->client->appointments()->count());
    }

    public function test_client_sees_an_inactive_or_deleted_service_as_one_that_does_not_exist(): void
    {
        $this->actingAsClient($this->client);

        $this->service->update(['status' => 'inactive']);
        $this->assertClientSeesNoService();

        $this->service->update(['status' => 'active']);
        $this->service->delete();
        $this->assertClientSeesNoService();

        $this->assertSame(0, Appointment::count());
    }

    /**
     * A agenda responde como a um id que não está no banco, igual ao 404 do GET
     * /api/services/{id}: o cliente não descobre se o serviço está inativo ou
     * foi excluído.
     */
    private function assertClientSeesNoService(): void
    {
        $invalid = 'O valor escolhido no campo serviço é inválido.';

        $this->getJson("/api/services/{$this->service->id}")->assertNotFound();

        $this->getJson($this->slotsUri())
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', $invalid);

        $this->postJson('/api/appointments', $this->payload(['barber_id' => $this->barber->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', $invalid);
    }

    public function test_barber_still_sees_why_the_service_cannot_be_booked(): void
    {
        $this->service->update(['status' => 'inactive']);
        $this->actingAsBarber($this->barber);

        $this->getJson($this->slotsUri())
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', 'Este serviço está inativo e não pode ser agendado.');

        $this->postJson('/api/appointments', $this->payload(['client_id' => $this->client->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.service_id.0', 'Este serviço está inativo e não pode ser agendado.');
    }

    public function test_client_cannot_book_for_someone_else(): void
    {
        $this->actingAsClient($this->client);

        $this->postJson('/api/appointments', $this->payload([
            'barber_id' => $this->barber->id,
            'client_id' => $this->otherClient->id,
        ]))
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);

        $this->assertSame(0, Appointment::count());
    }

    public function test_client_sees_only_the_own_appointments(): void
    {
        $own = $this->appointment($this->barber, $this->client);
        $other = $this->appointment($this->otherBarber, $this->otherClient);
        $this->actingAsClient($this->client);

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.barber', ['id' => $this->barber->id, 'name' => 'Pedro']);

        // O filtro não abre os horários de outro cliente.
        $this->getJson("/api/appointments?client_id={$this->otherClient->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/appointments/{$own->id}")->assertOk();

        $this->getJson("/api/appointments/{$other->id}")->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/appointments/{$other->id}/status", ['status' => 'cancelled'])->assertForbidden()->assertExactJson(self::FORBIDDEN);

        $this->assertSame('scheduled', $other->fresh()->status->value);
    }

    public function test_client_cancels_up_to_two_hours_before(): void
    {
        // Agora são 08:00. O das 10:00 ainda pode ser cancelado (limite às 08:00 em ponto); o das 09:30, não.
        $inTime = $this->appointment($this->barber, $this->client, '10:00:00');
        $late = $this->appointment($this->otherBarber, $this->client, '09:30:00');
        $this->actingAsClient($this->client);

        $this->patchJson("/api/appointments/{$inTime->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('status_label', 'Cancelado');

        $this->patchJson("/api/appointments/{$late->id}/status", ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Cancelamento só até 2 horas antes do horário.');

        $this->assertSame('scheduled', $late->fresh()->status->value);
    }

    public function test_cancel_window_comes_from_the_config(): void
    {
        config(['barberflow.client_cancel_min_hours' => 3]);
        $appointment = $this->appointment($this->barber, $this->client, '10:30:00');
        $this->actingAsClient($this->client);

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'cancelled'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'Cancelamento só até 3 horas antes do horário.');

        config(['barberflow.client_cancel_min_hours' => 1]);

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'cancelled'])
            ->assertOk();
    }

    public static function statusesTheClientCannotSet(): array
    {
        return [
            'agendar de novo' => ['scheduled'],
            'confirmar' => ['confirmed'],
            'iniciar' => ['in_progress'],
            'concluir' => ['completed'],
            'faltou' => ['no_show'],
        ];
    }

    #[DataProvider('statusesTheClientCannotSet')]
    public function test_client_only_cancels(string $status): void
    {
        // Depois do início, para o 403 não depender do horário.
        $this->travelTo(Carbon::parse(self::MONDAY.' 10:05:00'));
        $appointment = $this->appointment($this->barber, $this->client);
        $this->actingAsClient($this->client);

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => $status])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);

        $this->assertSame('scheduled', $appointment->fresh()->status->value);
    }

    public function test_client_cannot_complete_an_appointment_in_progress(): void
    {
        $this->travelTo(Carbon::parse(self::MONDAY.' 10:05:00'));
        $appointment = $this->appointment($this->barber, $this->client, status: 'in_progress');
        $this->actingAsClient($this->client);

        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'completed'])
            ->assertForbidden();

        $this->assertSame('in_progress', $appointment->fresh()->status->value);
    }

    public function test_client_cannot_reschedule(): void
    {
        $appointment = $this->appointment($this->barber, $this->client);
        $this->actingAsClient($this->client);

        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '11:00'])
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);

        $this->putJson("/api/appointments/{$appointment->id}", ['notes' => 'Mudei de ideia'])
            ->assertForbidden();

        $this->assertSame('10:00:00', $appointment->fresh()->start_time);
        $this->assertNull($appointment->fresh()->notes);
    }

    public function test_client_without_the_confirmed_email_is_refused(): void
    {
        $appointment = $this->appointment($this->barber, $this->client);
        $this->actingAsClient($this->client, verified: false);

        $this->getJson('/api/appointments')->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->postJson('/api/appointments', $this->payload(['barber_id' => $this->barber->id]))->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->getJson("/api/appointments/{$appointment->id}")->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'cancelled'])->assertForbidden()->assertExactJson(self::UNVERIFIED);
        $this->getJson($this->slotsUri())->assertForbidden()->assertExactJson(self::UNVERIFIED);

        $this->assertSame(1, Appointment::count());
        $this->assertSame('scheduled', $appointment->fresh()->status->value);
    }

    public function test_client_account_without_a_client_record_is_refused(): void
    {
        // Conta de cliente confirmada, mas sem cadastro de cliente ligado a ela.
        $user = User::create(['name' => 'Sem Cadastro', 'email' => 'sem.cadastro@barberflow.test', 'password' => self::PASSWORD]);
        $user->forceFill(['role' => UserRole::Client, 'email_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/appointments')->assertForbidden()->assertExactJson(self::FORBIDDEN);

        $this->postJson('/api/appointments', $this->payload([
            'barber_id' => $this->barber->id,
            'client_id' => $this->client->id,
        ]))->assertForbidden()->assertExactJson(self::FORBIDDEN);

        $this->assertSame(0, Appointment::count());
    }

    public function test_user_without_role_is_refused_everywhere(): void
    {
        $appointment = $this->appointment($this->barber, $this->client);
        $user = User::create(['name' => 'Sem Perfil', 'email' => 'sem.perfil@barberflow.test', 'password' => self::PASSWORD]);
        $user->forceFill(['email_verified_at' => now()])->save();
        Sanctum::actingAs($user);

        $this->getJson('/api/appointments')->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->postJson('/api/appointments', $this->payload([
            'barber_id' => $this->barber->id,
            'client_id' => $this->client->id,
            'start_time' => '11:00',
        ]))->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->getJson("/api/appointments/{$appointment->id}")->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '11:00'])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->patchJson("/api/appointments/{$appointment->id}/status", ['status' => 'cancelled'])->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->getJson($this->slotsUri())->assertForbidden()->assertExactJson(self::FORBIDDEN);

        $this->assertSame(1, Appointment::count());
    }

    public static function profiles(): array
    {
        return [
            'admin' => ['actingAsAdmin'],
            'barbeiro' => ['actingAsBarber'],
            'cliente' => ['actingAsClient'],
        ];
    }

    #[DataProvider('profiles')]
    public function test_every_profile_reads_the_available_slots(string $login): void
    {
        $this->{$login}();

        $this->getJson($this->slotsUri())
            ->assertOk()
            ->assertJsonPath('data.0', '09:00');
    }

    public function test_barber_frees_in_the_slots_only_the_time_of_the_own_appointments(): void
    {
        $own = $this->appointment($this->barber, $this->client);
        $other = $this->appointment($this->otherBarber, $this->otherClient);
        $this->actingAsBarber($this->barber);

        // 09:00, 09:30 e o próprio 10:00, que volta para a lista.
        $this->getJson($this->slotsUri()."&appointment_id={$own->id}")
            ->assertOk()
            ->assertJsonPath('data.2', '10:00');

        $this->getJson($this->slotsUri()."&appointment_id={$other->id}")
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);
    }

    public function test_client_does_not_free_times_in_the_slots(): void
    {
        // O cliente não remarca: cancela e agenda de novo.
        $own = $this->appointment($this->barber, $this->client);
        $this->actingAsClient($this->client);

        $this->getJson($this->slotsUri()."&appointment_id={$own->id}")
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN);
    }

    public function test_admin_books_for_anyone_and_moves_between_barbers(): void
    {
        $this->appointment($this->barber, $this->client);
        $this->actingAsAdmin();

        $id = $this->postJson('/api/appointments', $this->payload([
            'barber_id' => $this->otherBarber->id,
            'client_id' => $this->otherClient->id,
        ]))
            ->assertCreated()
            ->json('id');

        $this->patchJson("/api/appointments/{$id}", ['barber_id' => $this->barber->id, 'start_time' => '11:00'])
            ->assertOk()
            ->assertJsonPath('barber_id', $this->barber->id);

        $this->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
