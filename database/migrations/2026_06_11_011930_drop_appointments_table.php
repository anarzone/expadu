<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * v2 pivot: drops the appointments table. Appointment warnings were a
 * RecommendationService feature; bureaucracy deadlines now come from
 * UserTask via the TileComposer and BureaucracyEvaluator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('appointments');
    }

    public function down(): void
    {
        // Intentionally irreversible — restore from the original create
        // migration in git history if appointments ever return.
    }
};
