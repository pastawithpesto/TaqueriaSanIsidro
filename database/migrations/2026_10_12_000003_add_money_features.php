<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // total = productos + empaque - descuento. La propina va aparte y no cuenta como venta.
            $table->decimal('discount', 10, 2)->default(0)->after('packaging_fee');
            $table->decimal('tip', 10, 2)->default(0)->after('discount');
        });

        // Cada pago de una orden: uno normalmente, dos si fue mixto.
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->decimal('amount', 10, 2);
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['method', 'created_at']);
        });

        // Efectivo que se saca o se mete a la caja fuera de ventas y gastos.
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 3); // in / out
            $table->decimal('amount', 10, 2);
            $table->string('reason', 120)->nullable();
            $table->timestamp('moved_at')->index();
            $table->timestamps();
        });

        // Las órdenes pagadas que ya existan quedan con su pago registrado.
        DB::table('orders')->where('status', 'paid')->whereNotNull('payment_method')->orderBy('id')->each(function ($o) {
            DB::table('order_payments')->insert([
                'order_id' => $o->id, 'method' => $o->payment_method, 'amount' => $o->total,
                'reference' => $o->payment_reference, 'created_at' => $o->paid_at ?? $o->sold_at, 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('order_payments');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['discount', 'tip']);
        });
    }
};
