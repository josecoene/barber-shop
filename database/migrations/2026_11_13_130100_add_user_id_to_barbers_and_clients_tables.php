<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cadastros que podem ter login: o barbeiro (o admin cria o login dele) e o
     * cliente (a conta se liga ao cadastro quando ele confirma o e-mail).
     *
     * @var list<string>
     */
    private array $tables = ['barbers', 'clients'];

    /**
     * user_id é opcional (nem todo cadastro tem login) e único (um login para um
     * cadastro só). Se o usuário for apagado, o cadastro continua, sem login.
     */
    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropUnique(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
