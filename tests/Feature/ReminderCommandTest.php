<?php

namespace Tests\Feature;

use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use App\Services\ReminderSender;
use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Comando appointments:send-reminders (App\Services\ReminderSender). O "agora"
 * fica fixo na segunda-feira 16/11/2026 ao meio-dia, no fuso America/Sao_Paulo,
 * e a janela é a padrão, de 24 horas: vai até terça 17/11 ao meio-dia. Os
 * agendamentos são gravados direto no banco, sem passar pelas regras da agenda.
 * Os testes da reserva usam o beforeQuery() do tests/TestCase.php para mudar a
 * linha logo antes do UPDATE da reserva, ou do que a desfaz, como faria outro
 * pedido chegando no meio da rodada. O "Enviar lembrete agora" fica no
 * ReminderApiTest, e o conteúdo do e-mail no ReminderMailTest.
 */
class ReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-11-16';

    private const TUESDAY = '2026-11-17';

    private const COMMAND = 'appointments:send-reminders';

    /**
     * Os UPDATE que gravam o reminder_sent_at, no SQL sem aspas do
     * beforeQuery(). Numa rodada, o primeiro é a reserva da linha
     * (ReminderSender::reserve()) e, quando o envio falha, o segundo a desfaz
     * (release()). A conta não depende das condições do WHERE, que são o que
     * os testes conferem.
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
    private function appointmentAt(string $date, string $time, array $overrides = []): Appointment
    {
        $start = Carbon::parse("{$date} {$time}");

        return Appointment::create(array_merge([
            'client_id' => $this->client->id,
            'barber_id' => $this->barber->id,
            'service_id' => $this->service->id,
            'date' => $date,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $start->copy()->addMinutes(30)->format('H:i:s'),
            'price' => 35,
        ], $overrides));
    }

    private function assertReminderSentFor(Appointment $appointment, string $email = 'ana@barberflow.test'): void
    {
        Mail::assertSent(
            AppointmentReminder::class,
            fn (AppointmentReminder $mail) => $mail->appointmentId() === $appointment->id && $mail->hasTo($email),
        );
    }

    private function assertNoReminderFor(Appointment $appointment): void
    {
        Mail::assertNotSent(AppointmentReminder::class, fn (AppointmentReminder $mail) => $mail->appointmentId() === $appointment->id);
    }

    public function test_sends_the_reminder_of_what_starts_in_the_next_24_hours(): void
    {
        $inOneHour = $this->appointmentAt(self::MONDAY, '13:00');
        $tomorrowMorning = $this->appointmentAt(self::TUESDAY, '09:00');
        $inExactly24Hours = $this->appointmentAt(self::TUESDAY, '12:00');
        $afterTheWindow = $this->appointmentAt(self::TUESDAY, '12:30');
        $startingNow = $this->appointmentAt(self::MONDAY, '12:00');
        $alreadyStarted = $this->appointmentAt(self::MONDAY, '11:30');
        $nextWeek = $this->appointmentAt('2026-11-23', '10:00');

        $this->artisan(self::COMMAND)
            ->expectsOutput('Enviados: 3. Pulados: 0. Com falha: 0.')
            ->assertSuccessful();

        // Na hora, sem fila.
        Mail::assertSentCount(3);
        Mail::assertNothingQueued();

        foreach ([$inOneHour, $tomorrowMorning, $inExactly24Hours] as $appointment) {
            $this->assertReminderSentFor($appointment);
            $this->assertSame(self::MONDAY.' 12:00:00', $appointment->fresh()->reminder_sent_at?->toDateTimeString());
        }

        foreach ([$afterTheWindow, $startingNow, $alreadyStarted, $nextWeek] as $appointment) {
            $this->assertNoReminderFor($appointment);
            $this->assertNull($appointment->fresh()->reminder_sent_at);
        }
    }

    public function test_the_window_follows_the_config(): void
    {
        config(['barberflow.reminders.hours_before' => 2]);

        $inOneHour = $this->appointmentAt(self::MONDAY, '13:00');
        $inThreeHours = $this->appointmentAt(self::MONDAY, '15:00');

        $this->artisan(self::COMMAND)
            ->expectsOutput('Enviados: 1. Pulados: 0. Com falha: 0.')
            ->assertSuccessful();

        $this->assertReminderSentFor($inOneHour);
        $this->assertNull($inThreeHours->fresh()->reminder_sent_at);
    }

    public function test_the_window_also_crosses_the_night(): void
    {
        // Às 22:00 de segunda, a janela vai até as 22:00 de terça: o filtro por dia pega os dois dias.
        $this->travelTo(Carbon::parse(self::MONDAY.' 22:00:00'));

        $tomorrowMorning = $this->appointmentAt(self::TUESDAY, '09:00');
        $tomorrowAfterTheWindow = $this->appointmentAt(self::TUESDAY, '22:30');

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 1. Pulados: 0. Com falha: 0.')->assertSuccessful();

        $this->assertReminderSentFor($tomorrowMorning);
        $this->assertNull($tomorrowAfterTheWindow->fresh()->reminder_sent_at);
    }

    public function test_does_not_send_the_same_reminder_twice(): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00');

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 1. Pulados: 0. Com falha: 0.')->assertSuccessful();

        $this->travel(15)->minutes();

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 0. Com falha: 0.')->assertSuccessful();

        Mail::assertSentCount(1);
        // Fica a hora do primeiro envio.
        $this->assertSame(self::MONDAY.' 12:00:00', $appointment->fresh()->reminder_sent_at?->toDateTimeString());
    }

    public function test_confirmed_appointment_also_gets_the_reminder(): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00', ['status' => 'confirmed']);

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 1. Pulados: 0. Com falha: 0.')->assertSuccessful();

        $this->assertReminderSentFor($appointment);
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
    public function test_skips_the_statuses_without_reminder(string $status): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00', ['status' => $status]);

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 0. Com falha: 0.')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_skips_deleted_client_and_the_email_of_deleted_records(): void
    {
        $deleted = Client::create(['name' => 'Bruno', 'email' => 'bruno@barberflow.test']);
        $reserved = Client::create(['name' => 'Carla', 'email' => 'carla@barberflow.test']);

        $ofAna = $this->appointmentAt(self::TUESDAY, '09:00');
        $ofDeleted = $this->appointmentAt(self::TUESDAY, '10:00', ['client_id' => $deleted->id]);
        $ofReserved = $this->appointmentAt(self::TUESDAY, '11:00', ['client_id' => $reserved->id]);

        // Estados que a API não cria (excluir com agendamento futuro dá 409, e a validação recusa o
        // domínio), gravados direto no banco: o comando confere de novo antes de enviar.
        DB::table('clients')->where('id', $deleted->id)->update(['deleted_at' => now()]);
        DB::table('clients')->where('id', $reserved->id)->update(['email' => 'Removido+9@BarberFlow.Invalid']);

        $this->artisan(self::COMMAND)
            ->expectsOutput('Enviados: 1. Pulados: 2. Com falha: 0.')
            ->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertReminderSentFor($ofAna);
        $this->assertNoReminderFor($ofDeleted);
        $this->assertNoReminderFor($ofReserved);
        $this->assertNull($ofDeleted->fresh()->reminder_sent_at);
        $this->assertNull($ofReserved->fresh()->reminder_sent_at);
    }

    public function test_skips_the_client_anonymized_by_the_deletion(): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00');

        // Direto no model: pela API, a exclusão com agendamento futuro ativo dá 409.
        $this->client->anonymizeAndDelete();

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 1. Com falha: 0.')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_a_failed_send_frees_the_reminder_for_the_next_round_and_logs_only_the_id(): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00');
        $this->failMailDelivery();
        Log::spy();

        $this->artisan(self::COMMAND)
            ->expectsOutput('Enviados: 0. Pulados: 0. Com falha: 1.')
            ->assertFailed();

        $this->assertNull($appointment->fresh()->reminder_sent_at);

        // Só o id: nem o e-mail, nem o nome, nem a mensagem do erro (que traz o endereço).
        Log::shouldHaveReceived('warning', ['Lembrete por e-mail não enviado.', ['appointment_id' => $appointment->id]])->once();
        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');

        // Na rodada seguinte, com o e-mail de volta, o lembrete sai.
        Mail::fake();
        $this->travel(15)->minutes();

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 1. Pulados: 0. Com falha: 0.')->assertSuccessful();

        $this->assertReminderSentFor($appointment);
        $this->assertSame(self::MONDAY.' 12:15:00', $appointment->fresh()->reminder_sent_at?->toDateTimeString());
    }

    public function test_two_rounds_at_the_same_time_send_the_reminder_once(): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00');
        $otherRound = null;

        // Outra rodada (outro servidor, ou a trava do withoutOverlapping vencida) chega entre a leitura dos
        // candidatos e a reserva desta: ela reserva e manda primeiro, e esta não pega a reserva.
        $this->beforeQuery(self::REMINDER_UPDATE, function () use (&$otherRound) {
            $otherRound = app(ReminderSender::class)->sendDue();
        });

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 1. Com falha: 0.')->assertSuccessful();

        $this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $otherRound);
        Mail::assertSentCount(1);
        $this->assertReminderSentFor($appointment);
    }

    public function test_the_round_does_not_repeat_the_reminder_sent_now_after_the_read(): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00');

        // O "Enviar lembrete agora" sai entre a leitura dos candidatos e a reserva da rodada.
        $this->beforeQuery(self::REMINDER_UPDATE, fn () => app(ReminderSender::class)->sendNow($appointment));

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 1. Com falha: 0.')->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertReminderSentFor($appointment);
        $this->assertSame(self::MONDAY.' 12:00:00', $appointment->fresh()->reminder_sent_at?->toDateTimeString());
    }

    /**
     * Mudanças no agendamento entre a leitura dos candidatos e a reserva. Cada
     * item devolve as colunas novas.
     */
    public static function changesAfterTheRead(): array
    {
        return [
            'cancelado' => [fn (): array => ['status' => 'cancelled']],
            'remarcado para outro horário' => [fn (): array => ['start_time' => '11:00:00', 'end_time' => '11:30:00']],
            'remarcado para outra data' => [fn (): array => ['date' => '2026-11-18']],
            'passado para outro barbeiro' => [fn (): array => ['barber_id' => Barber::create(['name' => 'João', 'email' => 'joao@barberflow.test'])->id]],
            'trocado de serviço' => [fn (): array => ['service_id' => Service::create(['name' => 'Barba', 'duration' => 30, 'price' => 25])->id]],
        ];
    }

    #[DataProvider('changesAfterTheRead')]
    public function test_skips_the_appointment_that_changed_after_the_read(Closure $changes): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00');
        $columns = $changes();

        // A reserva confere a linha como foi lida: o e-mail nunca fala de um horário que já não vale.
        $this->beforeQuery(self::REMINDER_UPDATE, fn () => DB::table('appointments')->where('id', $appointment->id)->update($columns));

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 1. Com falha: 0.')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_a_failed_send_keeps_the_reminder_of_the_new_time_saved_meanwhile(): void
    {
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00');
        $this->failMailDelivery();

        // Enquanto o e-mail das 10:00 não sai, o agendamento é remarcado para as 11:00 (a remarcação zera o
        // reminder_sent_at) e o lembrete do horário novo sai pelo "Enviar lembrete agora", no mesmo segundo
        // da reserva. A falha desfaz só a própria reserva, a da data e do horário reservados: o lembrete do
        // horário novo fica, e a rodada seguinte não manda outro.
        $this->beforeQuery(self::REMINDER_UPDATE, fn () => DB::table('appointments')->where('id', $appointment->id)->update([
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'reminder_sent_at' => $appointment->fromDateTime(now()),
        ]), occurrence: 2);

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 0. Com falha: 1.')->assertFailed();

        $this->assertSame('11:00:00', $appointment->fresh()->start_time);
        $this->assertSame(self::MONDAY.' 12:00:00', $appointment->fresh()->reminder_sent_at?->toDateTimeString());

        Mail::fake();
        $this->travel(15)->minutes();

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 0. Com falha: 0.')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_rescheduling_to_another_date_or_time_frees_a_new_reminder(): void
    {
        $this->actingAsAdmin();
        $other = Barber::create(['name' => 'João', 'email' => 'joao@barberflow.test']);
        $appointment = $this->appointmentAt(self::TUESDAY, '10:00');

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 1. Pulados: 0. Com falha: 0.')->assertSuccessful();

        // Outro barbeiro e as observações, no mesmo dia e horário: o lembrete que saiu continua valendo.
        $this->patchJson("/api/appointments/{$appointment->id}", ['barber_id' => $other->id, 'notes' => 'Prefere máquina 2'])
            ->assertOk()
            ->assertJsonPath('reminder_sent_at', now()->toJSON());

        // Outro horário: o lembrete volta a nulo, e o comando manda o do horário novo.
        $this->patchJson("/api/appointments/{$appointment->id}", ['start_time' => '11:00'])
            ->assertOk()
            ->assertJsonPath('start_time', '11:00:00')
            ->assertJsonPath('reminder_sent_at', null);

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 1. Pulados: 0. Com falha: 0.')->assertSuccessful();

        Mail::assertSentCount(2);

        // Outra data, fora da janela: nulo até chegar a hora dele.
        $this->patchJson("/api/appointments/{$appointment->id}", ['date' => '2026-11-18'])
            ->assertOk()
            ->assertJsonPath('reminder_sent_at', null);

        $this->artisan(self::COMMAND)->expectsOutput('Enviados: 0. Pulados: 0. Com falha: 0.')->assertSuccessful();

        $this->assertNull($appointment->fresh()->reminder_sent_at);
    }

    public function test_the_command_runs_every_15_minutes_on_one_server_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains((string) $event->command, self::COMMAND))
            ->values();

        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->onOneServer);
        $this->assertTrue($events[0]->withoutOverlapping);
        // A trava vence antes da próxima rodada: um servidor que caiu no meio não segura os lembretes.
        $this->assertSame(10, $events[0]->expiresAt);
    }
}
