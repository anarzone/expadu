<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('spots')
            ->whereNull('source')
            ->update([
                'is_active' => false,
                'is_recommendable' => false,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Provenance cannot be reconstructed safely. A rollback must not
        // silently make unknown legacy rows recommendation-eligible again.
    }
};
