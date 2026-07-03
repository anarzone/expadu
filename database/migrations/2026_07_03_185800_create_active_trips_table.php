<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The journey a user is currently travelling, kept server-side so it
     * survives an app close and shows across every screen (the app-wide "trip
     * in progress" banner) until the user ends it. One active trip per user —
     * starting a new one replaces the old, ending one deletes the row.
     */
    public function up(): void
    {
        Schema::create('active_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('journey');            // the chosen Journey (legs + times)
            $table->json('origin')->nullable(); // {name, lat, lng} — null = live location
            $table->json('destination');        // {name, lat, lng, emoji?}
            $table->timestamp('started_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_trips');
    }
};
