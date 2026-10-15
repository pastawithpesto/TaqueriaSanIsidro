<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rastro de cancelaciones (cuándo y por qué) y de cambios a un pedido que la cocina ya tenía,
     * para avisarle que no lo prepare o que lo revise.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('delivered_at');
            $table->string('cancel_reason', 40)->nullable()->after('cancelled_at');
            $table->timestamp('changed_at')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'cancel_reason', 'changed_at']);
        });
    }
};
