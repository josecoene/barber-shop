<?php

namespace App\Console\Commands;

use App\Services\ReminderSender;
use Illuminate\Console\Command;

/**
 * Manda o lembrete por e-mail dos agendamentos agendados ou confirmados que
 * começam nas próximas REMINDER_HOURS_BEFORE horas e ainda não receberam
 * (regras no App\Services\ReminderSender), na hora e sem fila. É a mesma rodada
 * que o agendador faz a cada 15 minutos (routes/console.php), para rodar na mão;
 * o agendador chama o ReminderSender dentro do próprio processo, para o log da
 * rodada não se perder.
 *
 * No fim mostra quantos lembretes saíram, quantos foram pulados e quantos
 * falharam. Com alguma falha, termina com o código 1; os que falharam voltam a
 * ser tentados na próxima rodada.
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
