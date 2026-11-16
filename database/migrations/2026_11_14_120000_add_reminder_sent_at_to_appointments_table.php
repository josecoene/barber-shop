<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando saiu o lembrete por e-mail do agendamento (App\Services\ReminderSender).
     * Nulo, o lembrete ainda não saiu: o comando appointments:send-reminders só
     * envia para essas linhas e grava a hora antes de enviar, para o mesmo
     * lembrete não sair duas vezes. Remarcar a data ou o horário volta a nulo.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
