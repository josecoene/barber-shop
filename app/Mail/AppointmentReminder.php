<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Lembrete por e-mail do horário marcado, em Markdown e em pt-BR
 * (resources/views/mail/appointment-reminder.blade.php). Quem manda é o
 * App\Services\ReminderSender, na hora, sem fila.
 *
 * Mostra a data, o horário, o serviço, o barbeiro, os dados da barbearia
 * (config('barberflow.barbershop'): o endereço e o telefone vazios ficam de
 * fora) e como cancelar: até CLIENT_CANCEL_MIN_HOURS horas antes, pelo Meus
 * horários (link montado pelo APP_URL) quando o cliente tem conta, ou falando
 * com a barbearia quando não tem.
 *
 * O assunto é curto e sem dado pessoal. O cabeçalho X-Appointment-Id leva só o
 * id do agendamento, para achar a mensagem sem abrir o corpo (por exemplo, no
 * storage/logs/laravel.log, com MAIL_MAILER=log).
 */
class AppointmentReminder extends Mailable
{
    /**
     * O agendamento fica privado: o template recebe só o que content() entrega,
     * sem observações nem dados de contato.
     */
    public function __construct(private readonly Appointment $appointment)
    {
        // Sempre em pt-BR, mesmo com outro APP_LOCALE: o rodapé do layout do Laravel usa __().
        $this->locale('pt_BR');
    }

    /**
     * Id do agendamento deste lembrete.
     */
    public function appointmentId(): int
    {
        return (int) $this->appointment->getKey();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Lembrete do seu horário na '.$this->barbershop()['name']);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Appointment-Id' => (string) $this->appointmentId()]);
    }

    public function content(): Content
    {
        $appointment = $this->appointment;
        $startsAt = $appointment->startsAt();

        return new Content(markdown: 'mail.appointment-reminder', with: [
            'date' => $startsAt->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY'),
            'time' => $startsAt->format('H:i'),
            'service' => (string) $appointment->service?->name,
            'barber' => (string) $appointment->barber?->name,
            'barbershop' => $this->barbershop(),
            'cancelUntil' => $this->cancelUntil(),
            'myAppointmentsUrl' => $appointment->client?->has_login ? $this->myAppointmentsUrl() : null,
        ]);
    }

    /**
     * Nome, endereço e telefone da barbearia. Sem nome na config, vale o APP_NAME.
     *
     * @return array{name: string, address: string, phone: string}
     */
    private function barbershop(): array
    {
        $barbershop = (array) config('barberflow.barbershop');

        return [
            'name' => filled($barbershop['name'] ?? null) ? (string) $barbershop['name'] : (string) config('app.name'),
            'address' => trim((string) ($barbershop['address'] ?? '')),
            'phone' => trim((string) ($barbershop['phone'] ?? '')),
        ];
    }

    /**
     * Até quando o cliente cancela, a mesma janela que a agenda aplica ao
     * cancelamento pela conta (config('barberflow.client_cancel_min_hours')).
     */
    private function cancelUntil(): string
    {
        $hours = max(0, (int) config('barberflow.client_cancel_min_hours'));

        return match ($hours) {
            0 => 'até o horário marcado',
            1 => 'até 1 hora antes do horário',
            default => "até {$hours} horas antes do horário",
        };
    }

    /**
     * Link do Meus horários pelo APP_URL, e não pelo endereço da requisição: o
     * comando roda sem requisição, e o link do e-mail não pode depender de quem
     * pediu o envio. Sem login, a tela leva ao login e volta para cá depois.
     */
    private function myAppointmentsUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('my-appointments.index', absolute: false);
    }
}
