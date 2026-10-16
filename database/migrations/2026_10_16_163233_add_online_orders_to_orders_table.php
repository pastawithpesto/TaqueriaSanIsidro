<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedidos que hacen los clientes desde la página pública. Llegan "Por aceptar" y el taquero decide.
     * public_token es el enlace secreto con el que el cliente ve cómo va su pedido.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 10)->default('pos')->after('status');
            $table->string('customer_phone', 20)->nullable()->after('label');
            $table->uuid('public_token')->nullable()->unique()->after('customer_phone');
            $table->timestamp('accepted_at')->nullable()->after('sold_at');
            $table->index(['source', 'status']);
            // Los motivos de rechazo se escriben a mano y cabían mal en 40.
            $table->string('cancel_reason', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['source', 'status']);
            $table->dropUnique(['public_token']);
            $table->dropColumn(['source', 'customer_phone', 'public_token', 'accepted_at']);
            $table->string('cancel_reason', 40)->nullable()->change();
        });
    }
};
