<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routines from trips (track, week 3), under the personalisation pseudonym like the rest of what
 * personalisation keeps, and erased with it.
 *
 * trip_history: each trip started, with personalisation on, for 90 days: where to (its name, and a
 * key ~100 m wide), whether it set off from their Home or Work (never where they were), and when.
 * routines: the trips they confirmed as a routine ("You often go to work around 8:10…"), and the
 * offers they turned down, so those aren't offered again for a while.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_history', function (Blueprint $table) {
            $table->id();
            $table->char('subject_key', 64)->index();
            $table->string('to_key', 64);
            $table->string('to_label', 200);
            // home or work when it set off from there; nothing else is kept of where it started.
            $table->string('from_key', 16)->nullable();
            $table->timestampTz('started_at')->index();
            // In Cologne's time: 1 (Monday) to 7, and minutes since midnight.
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('minute');
            $table->index(['subject_key', 'to_key']);
        });

        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->char('subject_key', 64)->index();
            $table->string('to_key', 64);
            $table->string('to_label', 200);
            $table->string('from_key', 16)->nullable();
            $table->json('weekdays');
            $table->string('at', 5);
            $table->boolean('remind')->default(true);
            // Offered and turned down ("Not now"): not offered again until then.
            $table->timestampTz('dismissed_until')->nullable();
            $table->timestampsTz();
            $table->unique(['subject_key', 'to_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routines');
        Schema::dropIfExists('trip_history');
    }
};
