<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * KVB news keyed on its exact heading stored a reworded heading as a second
     * notice ("Trennung der Linien 1, 7, 9" beside "…1, 7 und 9"), each with its
     * own brief. Close all but the newest of each open twin set.
     */
    public function up(): void
    {
        DB::table('city_news')->where('source', 'kvb')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')->get(['id', 'title'])
            ->groupBy(fn ($row) => trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\b(und|and)\b|[,+&]/u', ' ', mb_strtolower((string) $row->title)))))
            ->each(function ($twins): void {
                $older = $twins->slice(1)->pluck('id');
                if ($older->isNotEmpty()) {
                    DB::table('city_news')->whereIn('id', $older)->update(['expires_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        // Data repair; nothing to undo.
    }
};
