<?php

namespace Tests\Feature;

use App\Mail\AppointmentReminder;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Conteúdo do lembrete por e-mail (App\Mail\AppointmentReminder): o agendamento
 * da Ana com o Pedro na terça 17/11/2026 às 10:00, montado de verdade (HTML e
 * texto), com os dados da barbearia da config.
 */
class ReminderMailTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));

        config([
            'app.url' => 'https://barberflow.example',
            'barberflow.barbershop' => [
                'name' => 'Navalha de Ouro',
                'address' => 'Rua das Flores, 100',
                'phone' => '(67) 3333-4444',
            ],
        ]);

        $this->client = Client::create(['name' => 'Ana Souza', 'email' => 'ana@barberflow.test', 'phone' => '67999990000']);
        $barber = Barber::create(['name' => 'Pedro', 'email' => 'pedro@barberflow.test', 'phone' => '67988880000']);
        $service = Service::create(['name' => 'Corte e barba', 'duration' => 60, 'price' => 50]);

        $this->appointment = Appointment::create([
            'client_id' => $this->client->id,
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'date' => '2026-11-17',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'price' => 50,
            'notes' => 'Alergia a lâmina descartável',
        ]);
    }

    private function mail(): AppointmentReminder
    {
        return new AppointmentReminder($this->appointment->fresh());
    }

    public function test_shows_the_appointment_and_the_barbershop(): void
    {
        $mail = $this->mail();

        $mail->assertHasSubject('Lembrete do seu horário na Navalha de Ouro');

        foreach (['terça-feira, 17/11/2026', '10:00', 'Corte e barba', 'Pedro', 'Navalha de Ouro', 'Rua das Flores, 100', '(67) 3333-4444'] as $text) {
            $mail->assertSeeInHtml($text);
            $mail->assertSeeInText($text);
        }
    }

    public function test_has_no_personal_data_beyond_the_appointment(): void
    {
        $mail = $this->mail();

        // O assunto não leva nome nem e-mail; o corpo não leva as observações nem os contatos das pessoas.
        foreach (['Ana', 'ana@barberflow.test', '67999990000', '67988880000', 'Alergia', 'pedro@barberflow.test'] as $text) {
            $mail->assertDontSeeInHtml($text);
            $mail->assertDontSeeInText($text);
        }

        $this->assertStringNotContainsString('Ana', $mail->envelope()->subject);
    }

    public function test_client_with_an_account_cancels_in_my_appointments_by_the_app_url(): void
    {
        $this->makeClientUser($this->client);

        $mail = $this->mail();

        $mail->assertSeeInHtml('Você pode cancelar pela sua conta, em Meus horários, até 2 horas antes do horário.');
        $mail->assertSeeInHtml('https://barberflow.example/my-appointments');
        $mail->assertSeeInText('https://barberflow.example/my-appointments');
        $mail->assertDontSeeInHtml('Fale com a barbearia');
    }

    public function test_client_without_an_account_talks_to_the_barbershop(): void
    {
        $mail = $this->mail();

        $mail->assertSeeInHtml('Fale com a barbearia até 2 horas antes do horário.');
        $mail->assertSeeInText('Fale com a barbearia até 2 horas antes do horário.');
        $mail->assertDontSeeInHtml('my-appointments');
        $mail->assertDontSeeInHtml('Meus horários');
    }

    public static function cancelWindows(): array
    {
        return [
            'nenhuma hora' => [0, 'até o horário marcado'],
            'uma hora' => [1, 'até 1 hora antes do horário'],
            'três horas' => [3, 'até 3 horas antes do horário'],
        ];
    }

    #[DataProvider('cancelWindows')]
    public function test_the_cancel_window_comes_from_the_config(int $hours, string $expected): void
    {
        config(['barberflow.client_cancel_min_hours' => $hours]);

        $this->mail()->assertSeeInText("Fale com a barbearia {$expected}.");
    }

    public function test_without_address_and_phone_those_lines_are_left_out(): void
    {
        config([
            'app.name' => 'BarberFlow',
            'barberflow.barbershop' => ['name' => '', 'address' => '', 'phone' => ''],
        ]);

        $mail = $this->mail();

        // Sem o nome da barbearia, vale o APP_NAME.
        $mail->assertHasSubject('Lembrete do seu horário na BarberFlow');
        $mail->assertDontSeeInText('Endereço:');
        $mail->assertDontSeeInText('Telefone:');
    }

    public function test_is_in_portuguese_even_with_another_app_locale(): void
    {
        app()->setLocale('en');

        $mail = $this->mail();

        $mail->assertSeeInHtml('terça-feira, 17/11/2026');
        $mail->assertSeeInHtml('Todos os direitos reservados.');
    }

    public function test_carries_only_the_appointment_id_in_a_header_and_is_not_queued(): void
    {
        $mail = $this->mail();

        $this->assertSame(['X-Appointment-Id' => (string) $this->appointment->id], $mail->headers()->text);
        $this->assertSame($this->appointment->id, $mail->appointmentId());
        $this->assertNotInstanceOf(ShouldQueue::class, $mail);
    }
}
