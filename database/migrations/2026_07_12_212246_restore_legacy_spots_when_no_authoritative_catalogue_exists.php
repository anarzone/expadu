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
        $hasAuthoritativeCatalogue = DB::table('spots')
            ->whereNotNull('source')
            ->where('is_active', true)
            ->exists();

        if (! $hasAuthoritativeCatalogue) {
            DB::table('spots')
                ->whereNull('source')
                ->update([
                    'is_active' => true,
                    'is_recommendable' => true,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restored catalogue availability is intentionally not reversible.
    }
};
