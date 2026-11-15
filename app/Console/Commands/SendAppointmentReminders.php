<?php

namespace App\Console\Commands;

use App\Services\ReminderSender;
use Illuminate\Console\Command;

/**
 * Manda o lembrete por e-mail dos agendamentos agendados ou confirmados que
 * começam nas próximas REMINDER_HOURS_BEFORE horas e ainda não receberam
 * (regras no App\Services\ReminderSender). O agendador roda a cada 15 minutos
 * (routes/console.php), na hora e sem fila.
 *
 * No fim mostra quantos lembretes saíram, quantos foram pulados e quantos
 * falharam. Com alguma falha, termina com o código 1, para o monitoramento
 * perceber; os que falharam voltam a ser tentados na próxima rodada.
 */
class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Manda o lembrete por e-mail dos agendamentos que começam nas próximas horas (REMINDER_HOURS_BEFORE)';

    public function handle(ReminderSender $reminders): int
    {
        $result = $reminders->sendDue();

        $this->info("Enviados: {$result['sent']}. Pulados: {$result['skipped']}. Com falha: {$result['failed']}.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
