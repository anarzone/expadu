<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The city's slice of gtfs.de's static timetable: each trip's calls in the
     * area, by gtfs.de's own ids, so its realtime feed's skipped stops can be
     * placed (line, where, when).
     */
    public function up(): void
    {
        Schema::create('delfi_calls', function (Blueprint $table) {
            $table->string('trip_id', 32);
            $table->unsignedInteger('stop_sequence');
            $table->string('line', 16);
            $table->string('headsign')->nullable();
            $table->float('lat');
            $table->float('lng');
            // Seconds after the service day's noon minus 12 h (GTFS; past 24:00 is fine).
            $table->unsignedInteger('departs');

            $table->primary(['trip_id', 'stop_sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delfi_calls');
    }
};
