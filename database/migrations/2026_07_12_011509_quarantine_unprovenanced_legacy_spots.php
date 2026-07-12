<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Legacy rows must remain available until a complete authoritative
        // catalogue has actually been imported. Quarantining them during the
        // schema deployment can otherwise empty Places before the scheduled
        // boundary/OSM jobs have run.
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
