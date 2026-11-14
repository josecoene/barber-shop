<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagamento de um atendimento concluído: zero ou um por agendamento.
     *
     * - appointment_id é unique: o banco é a última barreira contra dois
     *   pagamentos para o mesmo atendimento (App\Services\PaymentRecorder). A
     *   chave estrangeira é restrict, como as da agenda.
     * - amount segue o price: decimal(8,2), até 999999.99.
     * - method: cash, pix, credit_card ou debit_card (App\Enums\PaymentMethod).
     * - status: pending, paid ou refunded (App\Enums\PaymentStatus). Sem
     *   padrão no banco: quem grava sempre diz o status, junto com o paid_at.
     * - paid_at é preenchido pelo servidor quando o pagamento vira pago.
     * - Não tem exclusão: estornar é mudar o status.
     *
     * O índice (status, method) serve aos filtros da listagem e ao dashboard,
     * que soma só os pagos, por forma de pagamento. Os filtros por período,
     * barbeiro e cliente olham o agendamento (índice da migration seguinte).
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 8, 2);
            $table->string('method');
            $table->string('status');
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'method']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
