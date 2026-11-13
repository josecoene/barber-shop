<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * role: perfil do usuário (admin, barber ou client; ver App\Enums\UserRole).
     * Não tem padrão: usuário sem perfil não entra, nem pelas telas nem pela API.
     * O perfil nunca vem do que o usuário envia: o código grava com forceFill.
     *
     * phone: telefone que o cliente informa ao criar a conta. O cadastro de
     * cliente (tabela clients) só nasce, ou é ligado à conta, quando ele confirma
     * o e-mail; até lá o telefone fica aqui.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable()->index()->after('email');
            $table->string('phone', 20)->nullable()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'phone']);
        });
    }
};
