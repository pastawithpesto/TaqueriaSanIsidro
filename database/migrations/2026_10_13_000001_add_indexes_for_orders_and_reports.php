<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres no crea índices para las llaves foráneas.
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id');
            $table->index('product_id');
        });

        Schema::table('order_payments', function (Blueprint $table) {
            $table->index('order_id');
        });

        // Ventas, Resumen y Pedidos filtran por la hora del cobro y por la de entrega.
        Schema::table('orders', function (Blueprint $table) {
            $table->rawIndex('(COALESCE(paid_at, sold_at))', 'orders_counted_at_index');
            $table->rawIndex('(COALESCE(pickup_at, sold_at))', 'orders_due_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_counted_at_index');
            $table->dropIndex('orders_due_at_index');
        });

        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['product_id']);
        });
    }
};
