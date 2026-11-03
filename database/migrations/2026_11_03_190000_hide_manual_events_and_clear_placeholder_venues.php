<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nothing fake (audit, 3 Nov 2026). The hand-written events were re-dated by their weekly
 * import and marked verified: of 10, 3 were false and none could be confirmed, so they are
 * hidden. "Siehe Beschreibung" names no place, but its venue put 92 upcoming events in Deutz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')->where('source', 'manual')->update(['status' => 'hidden']);

        $placeholder = "(location_name ~* 'siehe|s\\. beschreibung|^online$|^div\\.|^verschiedene')";
        DB::table('events')->whereRaw($placeholder)->update(['location' => null, 'venue_id' => null]);
        DB::table('venues')->whereRaw("name ~* 'siehe|s\\. beschreibung|^online$|^div\\.|^verschiedene'")
            ->whereNotExists(fn ($events) => $events->selectRaw('1')->from('events')->whereColumn('events.venue_id', 'venues.id'))
            ->delete();
    }

    public function down(): void
    {
        // The dates and points were false: nothing to put back.
    }
};
