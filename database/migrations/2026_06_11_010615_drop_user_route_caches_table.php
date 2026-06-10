<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * v2 pivot: drops the precomputed commute-route cache. Routines and the
 * leave-by commute features are dead; transit is planned on demand via
 * "take me there".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_route_caches');
    }

    public function down(): void
    {
        // Intentionally irreversible — restore from the original create
        // migration in git history if route precomputation ever returns.
    }
};
