<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * KVB news like "Bis 23.11.2026: Trennung der Linie 18" stored its end
     * date as published_at (and expired a week after it). Put the end where
     * it belongs and date the notice from when it was first seen.
     */
    public function up(): void
    {
        DB::table('city_news')
            ->where('source', 'kvb')
            ->where('published_at', '>', now())
            ->update([
                'expires_at' => DB::raw("date_trunc('day', published_at) + interval '1 day' - interval '1 second'"),
                'published_at' => DB::raw('created_at'),
            ]);
    }

    public function down(): void
    {
        // Data repair; nothing to undo.
    }
};
