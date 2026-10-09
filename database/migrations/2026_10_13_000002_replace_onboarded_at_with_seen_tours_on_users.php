<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** El tutorial único se cambia por un recorrido en cada pantalla: se guarda cuáles ya vio. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('seen_tours')->nullable()->after('remember_token');
            $table->dropColumn('onboarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable()->after('remember_token');
            $table->dropColumn('seen_tours');
        });
    }
};
