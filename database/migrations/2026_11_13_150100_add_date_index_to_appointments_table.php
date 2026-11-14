<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice (date, start_time) na agenda, para as consultas por período de
     * todos os barbeiros: o dashboard, os filtros from e to da listagem de
     * pagamentos e a ordem por data e horário das listagens. Os índices da
     * agenda começam pelo barbeiro ou pelo cliente e não servem a essas
     * consultas.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['date', 'start_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['date', 'start_time']);
        });
    }
};
