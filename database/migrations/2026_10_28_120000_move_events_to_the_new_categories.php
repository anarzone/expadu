<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What's on's new categories (owner, 28 Oct 2026): the old names with one certain new home move
 * now. Language exchange, Stammtisch and International (and the scraper's older "language" and
 * "social") are Meet people; the scraper's markets ("food") are Festivals & markets. Culture
 * has no single new home: events:recategorise sorts those rows, which read as Other until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')->whereIn('category', ['language_exchange', 'stammtisch', 'intl_meetup', 'language', 'social'])
            ->update(['category' => 'meet_people']);
        DB::table('events')->where('category', 'food')->update(['category' => 'festivals']);
    }

    public function down(): void
    {
        // One way: which old name a Meet people row had is not kept.
    }
};
