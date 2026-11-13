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
