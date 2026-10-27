<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a transit notice applies, beyond its lines: the stops a VRS (TRIAS)
 * situation names, and the trips a DELFI alert is attached to, so a board
 * row can show the notice that explains it (and no other).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('city_news', function (Blueprint $table) {
            $table->json('affected_stops')->nullable()->after('affected_lines');
            $table->json('affected_trips')->nullable()->after('affected_stops');
        });
    }

    public function down(): void
    {
        Schema::table('city_news', function (Blueprint $table) {
            $table->dropColumn(['affected_stops', 'affected_trips']);
        });
    }
};
