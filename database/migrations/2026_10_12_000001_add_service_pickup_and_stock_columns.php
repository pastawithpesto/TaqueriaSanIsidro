<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Agotado solo por ese día: al día siguiente vuelve a estar disponible.
            $table->date('sold_out_on')->nullable()->after('active');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('service', 10)->nullable()->after('label');
            $table->decimal('packaging_fee', 10, 2)->default(0)->after('total');
            $table->timestamp('pickup_at')->nullable()->after('sold_at');
            $table->timestamp('ready_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['service', 'packaging_fee', 'pickup_at', 'ready_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sold_out_on');
        });
    }
};
