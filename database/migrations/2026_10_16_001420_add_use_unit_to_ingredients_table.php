<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cómo se usa en las recetas cuando no es como se compra: el pan se compra por paquete y se usa por pieza.
     * use_per_unit dice cuántas de esas trae lo que se compra (1 paquete = 8 pza). Sin dato, se usa como se compra.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('use_unit', 20)->nullable()->after('cost_per_unit');
            $table->decimal('use_per_unit', 12, 4)->nullable()->after('use_unit');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['use_unit', 'use_per_unit']);
        });
    }
};
