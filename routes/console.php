<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Apaga os tokens da API vencidos há mais de 24 horas. O onOneServer() faz só uma
// instância rodar quando houver várias (precisa de cache compartilhado, como o Redis).
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

// Lembrete por e-mail dos agendamentos das próximas REMINDER_HOURS_BEFORE horas, na hora e sem fila
// (App\Services\ReminderSender). O withoutOverlapping() não deixa uma rodada começar antes de a anterior
// terminar; a trava dele vence em 10 minutos, para um servidor que caiu no meio da rodada não segurar os
// lembretes por um dia (o padrão do Laravel). Mesmo com duas rodadas juntas, a reserva da linha impede
// o lembrete repetido.
Schedule::command('appointments:send-reminders')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(10);
