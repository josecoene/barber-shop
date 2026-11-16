<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agenda: um horário de um cliente com um barbeiro para um serviço.
     *
     * - date fica como Y-m-d, e start_time e end_time sempre como H:i:s. O
     *   end_time é calculado pelo servidor (início + duração do serviço).
     * - status: scheduled, confirmed, in_progress, completed, cancelled ou
     *   no_show (App\Enums\AppointmentStatus). Não tem exclusão: cancelar é
     *   mudar o status.
     * - price é a cópia do preço do serviço no momento do agendamento, para uma
     *   mudança de preço depois não reescrever o histórico.
     * - As chaves estrangeiras impedem apagar de vez um cliente, barbeiro ou
     *   serviço com agendamento (restrict). A exclusão desses cadastros é lógica
     *   (soft delete) e a linha continua no banco para o histórico.
     *
     * Os índices servem às consultas da agenda: sobreposição e horários livres
     * do barbeiro no dia (barber_id, date, start_time) e sobreposição do cliente
     * (client_id, date).
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('barber_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status')->default('scheduled');
            $table->decimal('price', 8, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['barber_id', 'date', 'start_time']);
            $table->index(['client_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
