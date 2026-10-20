<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The plan a trip is for, as the day knew it when the trip started (its
     * title, kind and start), so the trip page can say "12 min spare" and
     * "Starts at 14:00". Only a plan on the person's own day is stored.
     */
    public function up(): void
    {
        Schema::table('active_trips', function (Blueprint $table) {
            $table->jsonb('target')->nullable()->after('destination');
        });
    }

    public function down(): void
    {
        Schema::table('active_trips', function (Blueprint $table) {
            $table->dropColumn('target');
        });
    }
};
