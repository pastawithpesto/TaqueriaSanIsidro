<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_address')->nullable()->after('service');
            $table->string('delivery_phone', 30)->nullable()->after('delivery_address');
            $table->string('delivery_notes', 160)->nullable()->after('delivery_phone');
            $table->decimal('delivery_fee', 10, 2)->default(0)->after('packaging_fee');
            $table->timestamp('dispatched_at')->nullable()->after('ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_address', 'delivery_phone', 'delivery_notes', 'delivery_fee', 'dispatched_at']);
        });
    }
};
