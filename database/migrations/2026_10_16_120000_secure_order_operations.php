<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('operation_token')->nullable()->unique();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('replaces_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
        });

        // Conserva también los resultados de formularios que editaron un pedido existente.
        Schema::create('order_operations', function (Blueprint $table) {
            $table->uuid('token')->primary();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_operations');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_order_id');
            $table->dropUnique(['operation_token']);
            $table->dropColumn(['operation_token', 'version']);
        });
    }
};
