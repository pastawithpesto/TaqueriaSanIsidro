<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('closed_on')->unique();
            $table->decimal('opening', 10, 2)->default(0);
            $table->decimal('expected', 10, 2);
            $table->decimal('counted', 10, 2);
            $table->decimal('difference', 10, 2);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_closes');
    }
};
