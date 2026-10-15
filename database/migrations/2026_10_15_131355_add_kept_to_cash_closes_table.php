<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lo que se deja en la caja al cerrar: es el fondo del día siguiente. Sin dato, se usa todo lo contado. */
    public function up(): void
    {
        Schema::table('cash_closes', function (Blueprint $table) {
            $table->decimal('kept', 10, 2)->nullable()->after('counted');
        });
    }

    public function down(): void
    {
        Schema::table('cash_closes', function (Blueprint $table) {
            $table->dropColumn('kept');
        });
    }
};
