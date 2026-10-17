<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El chat de Telegram al que se avisa de un pedido en línea, y los mensajes que se le mandaron
     * al taquero (para editarlos cuando el pedido se acepta o rechaza desde otro lado).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('telegram_chat_id')->nullable()->after('customer_phone')->index();
        });

        Schema::create('telegram_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('message_id');
            $table->timestamps();
            $table->unique(['order_id', 'chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_notices');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
