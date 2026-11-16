<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fidelidade no pagamento (App\Services\LoyaltyService):
     *
     * - discount: o desconto do prêmio, em dinheiro, no mesmo formato do amount
     *   (decimal(8,2)). Zero no pagamento sem prêmio;
     * - loyalty_reward: o pagamento usou o prêmio da fidelidade. O atendimento
     *   pago assim não conta como visita, e o prêmio volta a ficar disponível
     *   se o pagamento for estornado.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('discount', 8, 2)->default(0)->after('amount');
            $table->boolean('loyalty_reward')->default(false)->after('discount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['discount', 'loyalty_reward']);
        });
    }
};
