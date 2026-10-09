<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->default(null)->change();
            $table->string('label', 60)->nullable()->after('status');
            $table->unsignedInteger('daily_number')->nullable()->after('id');
            $table->timestamp('paid_at')->nullable()->after('sold_at');
            $table->timestamp('delivered_at')->nullable()->after('paid_at');
            $table->index(['status', 'delivered_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('modifiers')->nullable()->after('product_name');
            $table->string('notes', 120)->nullable()->after('modifiers');
        });

        Schema::table('product_ingredient', function (Blueprint $table) {
            $table->boolean('removable')->default(true)->after('quantity');
        });

        Schema::create('product_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('price', 10, 2)->default(0);
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_extras');

        Schema::table('product_ingredient', function (Blueprint $table) {
            $table->dropColumn('removable');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['modifiers', 'notes']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'delivered_at']);
            $table->dropColumn(['label', 'daily_number', 'paid_at', 'delivered_at']);
            $table->string('payment_method', 20)->default('cash')->nullable(false)->change();
        });
    }
};
