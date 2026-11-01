<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "We keep watching it": when the way was picked (KVB notices older than
     * that were in "We checked" already) and the worst change of plan pushed
     * for it so far (notice, late, cancelled), so each is sent once.
     */
    public function up(): void
    {
        Schema::table('planned_trips', function (Blueprint $table) {
            $table->timestampTz('planned_at')->nullable()->after('arrive_at');
            $table->string('alerted_kind', 16)->nullable()->after('reminded_at');
            $table->timestampTz('alerted_at')->nullable()->after('alerted_kind');
        });
    }

    public function down(): void
    {
        Schema::table('planned_trips', function (Blueprint $table) {
            $table->dropColumn(['planned_at', 'alerted_kind', 'alerted_at']);
        });
    }
};
