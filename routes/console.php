<?php

use App\Services\ReminderSender;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Apaga os tokens da API vencidos há mais de 24 horas. O onOneServer() faz só uma
// instância rodar quando houver várias (precisa de cache compartilhado, como o Redis).
// Fica como comando à parte, com a saída no /dev/null: ele não grava log, e, se falhar, o
// agendador registra no log dele que o comando terminou com erro (a causa aparece rodando
// na mão). A rodada do dia seguinte apaga também o que ficou para trás.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

// Lembrete por e-mail dos agendamentos das próximas REMINDER_HOURS_BEFORE horas, na hora e sem fila
// (App\Services\ReminderSender). A rodada roda dentro do processo do agendador (Schedule::call), e não
// como outro processo (Schedule::command): o schedule:run manda a saída de cada comando agendado para o
// /dev/null e, com o LOG_CHANNEL=stderr da homolog e da AWS, o log do lembrete que falhou sumia junto.
// Aqui, o log vai para a saída do schedule:run, que o schedule:work do container scheduler repassa para
// a saída dele (no local, com o log em arquivo, vai para o laravel.log como antes). Com alguma falha, a
// rodada termina como falha, com o motivo no log; os lembretes que falharam voltam na rodada seguinte.
// O name() dá o nome da rodada na saída do agendador e a chave das travas. O withoutOverlapping() não
// deixa uma rodada começar antes de a anterior terminar; a trava dele vence em 10 minutos, para um
// servidor que caiu no meio da rodada não segurar os lembretes por um dia (o padrão do Laravel). Mesmo
// com duas rodadas juntas, a reserva da linha impede o lembrete repetido. Para rodar na mão, com o
// resumo na tela: php artisan appointments:send-reminders.
Schedule::call(function (ReminderSender $reminders): void {
    $result = $reminders->sendDue();

    if ($result['failed'] > 0) {
        throw new RuntimeException("Lembretes por e-mail com falha nesta rodada: {$result['failed']}. Enviados: {$result['sent']}. Pulados: {$result['skipped']}.");
    }
})->name('appointments:send-reminders')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(10);
