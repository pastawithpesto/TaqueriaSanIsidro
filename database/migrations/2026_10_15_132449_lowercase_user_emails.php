<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** El login compara el correo en minúsculas: los que se crearon con mayúsculas ya no entrarían. */
    public function up(): void
    {
        DB::table('users')->update(['email' => DB::raw('LOWER(email)')]);
    }

    public function down(): void
    {
        //
    }
};
