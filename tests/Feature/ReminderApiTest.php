<?php

namespace Tests\Feature;

use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Enviar lembrete agora" (POST /api/appointments/{appointment}/reminder, regras
 * no App\Services\ReminderSender). O "agora" fica fixo na segunda-feira
 * 16/11/2026 ao meio-dia, no fuso America/Sao_Paulo; o agendamento padrão é o
 * da Ana com o Pedro na quinta 19/11 às 10:00, fora da janela de 24 horas do
 * comando (o envio manual vale a qualquer momento antes do início).
 */
class ReminderApiTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private const THURSDAY = '2026-11-19';

    private const FORBIDDEN = ['message' => 'Você não tem permissão para esta ação.'];

    private const DELETED_CLIENT = 'O cliente foi excluído: não há para quem enviar o lembrete.';

    private const NO_EMAIL = 'O cadastro do cliente não tem um e-mail que receba mensagens.';

    private const SEND_FAILED = 'Não foi possível enviar o lembrete agora. Tente de novo em alguns minutos.';

    /**
     * Os UPDATE que gravam o reminder_sent_at, no SQL sem aspas do
     * beforeQuery(). No envio manual, o primeiro grava o envio e, quando o
     * e-mail falha, o segundo o desfaz (ReminderSender::release()), seja qual
     * for o WHERE dele.
     */
    private const REMINDER_UPDATE = '/^update appointments set reminder_sent_at = /';

    private Client $client;

    private Barber $barber;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::MONDAY.' 12:00:00'));
        Mail::fake();

        $this->client = Client::create(['name' => 'Ana', 'email' => 'ana@barberflow.test']);
        $this->barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test']);
        $this->service = Service::create(['name' => 'Corte', 'duration' => 30, 'price' => 35]);
    }

    /**
     * Agendamento de 30 minutos da Ana com o Pedro, agendado, gravado direto no banco.
     */
    private function appointment(array $overrides = []): Appointment
    {
        $start = Carbon::parse(($overrides['date'] ?? self::THURSDAY).' '.($overrides['start_time'] ?? '10:00:00'));

        return Appointment::create(array_merge([
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => $start->toDateString(),
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'price' => 35,
        ], $overrides));
    }

    private function sendReminder(Appointment $appointment): TestResponse
    {
        return $this->postJson("/api/appointments/{$appointment->id}/reminder");
    }

    /**
     * O lembrete do agendamento saiu $times vezes, sempre para a Ana.
     */
    private function assertReminderSentFor(Appointment $appointment, int $times = 1): void
    {
        $sent = Mail::sent(
            AppointmentReminder::class,
            fn (AppointmentReminder $mail) => $mail->appointmentId() === $appointment->id && $mail->hasTo('ana@barberflow.test'),
        );

        $this->assertCount($times, $sent);
    }

    public function test_admin_sends_the_reminder_now_and_gets_the_appointment_back(): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment(['notes' => 'Prefere máquina 2']);

        $this->sendReminder($appointment)
            ->assertOk()
            ->assertJsonPath('id', $appointment->id)
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonPath('reminder_sent_at', now()->toJSON())
            // O mesmo resumo do GET /api/appointments/{id}: cliente, barbeiro e serviço só com id e nome.
            ->assertJsonPath('client', ['id' => $this->client->id, 'name' => 'Ana'])
            ->assertJsonPath('barber', ['id' => $this->barber->id, 'name' => 'Pedro'])
            ->assertJsonPath('service', ['id' => $this->service->id, 'name' => 'Corte']);

        // Na hora, sem fila.
        $this->assertReminderSentFor($appointment);
        Mail::assertNothingQueued();

        $this->assertSame(self::MONDAY.' 12:00:00', $appointment->fresh()->reminder_sent_at?->toDateTimeString());
    }

    public function test_barber_sends_the_reminder_of_the_own_appointment(): void
    {
        $this->actingAsBarber($this->barber);
        $appointment = $this->appointment();

        $this->sendReminder($appointment)->assertOk()->assertJsonPath('reminder_sent_at', now()->toJSON());

        $this->assertReminderSentFor($appointment);
    }

    public function test_barber_of_another_agenda_gets_403(): void
    {
        $this->actingAsBarber();
        $appointment = $this->appointment();

        $this->sendReminder($appointment)->assertForbidden()->assertExactJson(self::FORBIDDEN);

        Mail::assertNothingSent();
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_client_gets_403_even_for_the_own_appointment(): void
    {
        $own = $this->appointment();
        $other = $this->appointment([
            'client_id' => Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test'])->id,
            'start_time' => '11:00:00',
        ]);
        $this->actingAsClient($this->client);

        $this->sendReminder($own)->assertForbidden()->assertExactJson(self::FORBIDDEN);
        $this->sendReminder($other)->assertForbidden()->assertExactJson(self::FORBIDDEN);

        Mail::assertNothingSent();
        $this->assertNull($own->fresh()->reminder_sent_at);
    }

    public function test_without_login_gets_401(): void
    {
        $this->sendReminder($this->appointment())
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Não autenticado.']);

        Mail::assertNothingSent();
    }

    public function test_unknown_appointment_gets_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/appointments/999/reminder')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Registro não encontrado.']);
    }

    public static function statusesWithoutReminder(): array
    {
        return [
            'cancelado' => ['cancelled'],
            'faltou' => ['no_show'],
            'em atendimento' => ['in_progress'],
            'concluído' => ['completed'],
        ];
    }

    #[DataProvider('statusesWithoutReminder')]
    public function test_refuses_the_statuses_without_reminder(string $status): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment(['status' => $status]);

        $this->sendReminder($appointment)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reminder.0', 'Só é possível enviar o lembrete de um agendamento com status Agendado ou Confirmado.');

        Mail::assertNothingSent();
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_confirmed_appointment_also_gets_the_reminder(): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment(['status' => 'confirmed']);

        $this->sendReminder($appointment)->assertOk();

        $this->assertReminderSentFor($appointment);
    }

    public static function startedAppointments(): array
    {
        return [
            'começa agora' => ['12:00:00'],
            'começou há meia hora' => ['11:30:00'],
        ];
    }

    #[DataProvider('startedAppointments')]
    public function test_refuses_the_appointment_that_already_started(string $startTime): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment(['date' => self::MONDAY, 'start_time' => $startTime]);

        $this->sendReminder($appointment)
            ->assertUnprocessable()
            ->assertJsonPath('errors.reminder.0', 'Este agendamento já começou: não há lembrete para enviar.');

        Mail::assertNothingSent();
    }

    public function test_refuses_deleted_client_and_the_email_of_deleted_records(): void
    {
        $this->actingAsAdmin();
        $deleted = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']);
        $reserved = Client::create(['name' => 'Carla', 'email' => 'carla@barberflow.test']);
        $ofDeleted = $this->appointment(['client_id' => $deleted->id]);
        $ofReserved = $this->appointment(['client_id' => $reserved->id, 'start_time' => '11:00:00']);

        // Estados que a API não cria, gravados direto no banco.
        DB::table('clients')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        DB::table('clients')->where('id', $reserved->id)->update(['email' => 'removido+9@barberflow.invalid']);

        $this->sendReminder($ofDeleted)->assertUnprocessable()->assertJsonPath('errors.reminder.0', self::DELETED_CLIENT);
        $this->sendReminder($ofReserved)->assertUnprocessable()->assertJsonPath('errors.reminder.0', self::NO_EMAIL);

        Mail::assertNothingSent();
        $this->assertNull($ofDeleted->fresh()->reminder_sent_at);
        $this->assertNull($ofReserved->fresh()->reminder_sent_at);
    }

    public function test_one_send_every_10_minutes_per_appointment(): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment();
        $another = $this->appointment(['start_time' => '11:00:00']);

        $this->sendReminder($appointment)->assertOk();

        $this->sendReminder($appointment)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '600')
            ->assertExactJson(['message' => 'Um lembrete deste agendamento saiu há menos de 10 minutos. Tente de novo em 10 minutos.']);

        // O limite é por agendamento: o outro sai na hora.
        $this->sendReminder($another)->assertOk();

        $this->travel(9 * 60 + 59)->seconds();

        $this->sendReminder($appointment)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '1')
            ->assertExactJson(['message' => 'Um lembrete deste agendamento saiu há menos de 10 minutos. Tente de novo em 1 minuto.']);

        $this->travel(1)->seconds();

        $this->sendReminder($appointment)
            ->assertOk()
            ->assertJsonPath('reminder_sent_at', now()->toJSON());

        $this->assertReminderSentFor($appointment, times: 2);
        Mail::assertSentCount(3);
    }

    public function test_rescheduling_does_not_free_another_send_before_10_minutes(): void
    {
        $this->actingAsBarber($this->barber);
        $appointment = $this->appointment();

        $this->sendReminder($appointment)->assertOk();
        $this->travel(2)->minutes();

        // A remarcação volta o reminder_sent_at a nulo (o comando manda o lembrete do horário novo na
        // rodada dele), mas não libera outro envio manual: o último envio também fica guardado no cache.
        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '11:00'])
            ->assertOk()
            ->assertJsonPath('reminder_sent_at', null);

        $this->sendReminder($appointment)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '480')
            ->assertExactJson(['message' => 'Um lembrete deste agendamento saiu há menos de 10 minutos. Tente de novo em 8 minutos.']);

        // Remarcar de novo, de volta às 10:00, também não libera.
        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '10:00'])->assertOk();
        $this->sendReminder($appointment)->assertTooManyRequests()->assertHeader('Retry-After', '480');

        $this->travel(8)->minutes();

        $this->sendReminder($appointment)->assertOk()->assertJsonPath('reminder_sent_at', now()->toJSON());

        $this->assertReminderSentFor($appointment, times: 2);
    }

    public function test_each_user_sends_up_to_10_reminders_per_minute(): void
    {
        $this->actingAsAdmin();
        $appointments = [];

        // Onze agendamentos diferentes na quinta, de meia em meia hora a partir das 09:00: o limite por
        // agendamento não entra na conta.
        foreach (range(0, 10) as $slot) {
            $appointments[] = $this->appointment([
                'start_time' => Carbon::parse(self::THURSDAY.' 09:00:00')->addMinutes(30 * $slot)->format('H:i:s'),
            ]);
        }

        foreach (array_slice($appointments, 0, 10) as $appointment) {
            $this->sendReminder($appointment)->assertOk();
        }

        $this->sendReminder($appointments[10])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '60')
            ->assertExactJson(['message' => 'Muitas requisições. Tente de novo em 60 segundos.']);

        Mail::assertSentCount(10);
        $this->assertNull($appointments[10]->fresh()->reminder_sent_at);

        // O limite é por usuário: outro admin manda na hora.
        $this->actingAsAdmin();
        $this->sendReminder($appointments[10])->assertOk();

        Mail::assertSentCount(11);
    }

    public function test_the_limit_counts_the_reminder_of_the_command(): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment(['date' => self::TUESDAY]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->travel(4)->minutes();

        $this->sendReminder($appointment)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '360')
            ->assertJsonPath('message', 'Um lembrete deste agendamento saiu há menos de 10 minutos. Tente de novo em 6 minutos.');

        $this->assertReminderSentFor($appointment);
    }

    public function test_the_reminder_sent_now_counts_as_the_reminder_of_the_appointment(): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment(['date' => self::TUESDAY]);

        $this->sendReminder($appointment)->assertOk();

        // Já saiu: o comando não manda outro.
        $this->artisan('appointments:send-reminders')
            ->expectsOutput('Enviados: 0. Pulados: 0. Com falha: 0.')
            ->assertSuccessful();

        $this->assertReminderSentFor($appointment);
    }

    public function test_a_failed_send_returns_503_and_keeps_the_previous_reminder(): void
    {
        $this->actingAsAdmin();
        $neverSent = $this->appointment();
        $sentBefore = $this->appointment(['start_time' => '11:00:00']);
        DB::table('appointments')->where('id', $sentBefore->id)->update(['reminder_sent_at' => self::MONDAY.' 11:00:00']);

        $this->failMailDelivery();
        Log::spy();

        $this->sendReminder($neverSent)->assertServiceUnavailable()->assertJsonPath('message', self::SEND_FAILED);
        $this->sendReminder($sentBefore)->assertServiceUnavailable()->assertJsonPath('message', self::SEND_FAILED);

        // O reminder_sent_at volta ao que era: nulo (o comando tenta na hora dele) ou o do envio anterior.
        $this->assertNull($neverSent->fresh()->reminder_sent_at);
        $this->assertSame(self::MONDAY.' 11:00:00', $sentBefore->fresh()->reminder_sent_at?->toDateTimeString());

        // Só o id no log.
        Log::shouldHaveReceived('warning', ['Lembrete por e-mail não enviado.', ['appointment_id' => $neverSent->id]])->once();
        Log::shouldHaveReceived('warning', ['Lembrete por e-mail não enviado.', ['appointment_id' => $sentBefore->id]])->once();
        Log::shouldHaveReceived('warning')->twice();

        // O envio que falhou não conta no limite de 10 minutos: com o e-mail de volta, sai na hora.
        Mail::fake();

        $this->sendReminder($neverSent)->assertOk()->assertJsonPath('reminder_sent_at', now()->toJSON());

        $this->assertReminderSentFor($neverSent);
    }

    public function test_a_failed_send_does_not_undo_a_rescheduling_made_meanwhile(): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment();
        DB::table('appointments')->where('id', $appointment->id)->update(['reminder_sent_at' => self::MONDAY.' 11:00:00']);
        $this->failMailDelivery();

        // Enquanto o e-mail não sai, o agendamento é remarcado para as 15:00, e a remarcação volta o
        // reminder_sent_at a nulo. A falha desfaz só a própria reserva: ela não devolve o lembrete que saiu
        // às 11:00, que falava do horário antigo, senão o comando não mandaria o do horário novo.
        $this->beforeQuery(self::REMINDER_UPDATE, fn () => DB::table('appointments')->where('id', $appointment->id)->update([
            'start_time' => '15:00:00',
            'end_time' => '15:30:00',
            'reminder_sent_at' => null,
        ]), occurrence: 2);

        $this->sendReminder($appointment)->assertServiceUnavailable()->assertJsonPath('message', self::SEND_FAILED);

        $this->assertSame('15:00:00', $appointment->fresh()->start_time);
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_the_appointment_json_brings_the_reminder_sent_at(): void
    {
        $this->actingAsAdmin();
        $appointment = $this->appointment();

        $created = $this->postJson('/api/appointments', [
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => self::TUESDAY,
            'start_time' => '15:00',
        ])->assertCreated();

        $this->assertArrayHasKey('reminder_sent_at', $created->json());
        $this->assertNull($created->json('reminder_sent_at'));

        $shown = $this->getJson("/api/appointments/{$appointment->id}")->assertOk();
        $this->assertArrayHasKey('reminder_sent_at', $shown->json());

        $this->sendReminder($appointment)->assertOk();

        $this->getJson('/api/appointments?date='.self::THURSDAY)
            ->assertOk()
            ->assertJsonPath('data.0.id', $appointment->id)
            ->assertJsonPath('data.0.reminder_sent_at', now()->toJSON());
    }
}
