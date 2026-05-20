<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index('starts_at', 'events_starts_at_idx');
            $table->index(['category', 'starts_at'], 'events_category_starts_at_idx');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->index(['user_id', 'dismissed_at'], 'alerts_user_dismissed_idx');
            $table->index(['user_id', 'type', 'dismissed_at'], 'alerts_user_type_dismissed_idx');
        });

        Schema::table('user_events', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'event_type']);
            $table->dropIndex(['created_at']);
            $table->index(['user_id', 'event_type', 'created_at'], 'user_events_user_type_created_idx');
        });

        DB::statement('UPDATE spots SET location = ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography WHERE location IS NULL AND lat IS NOT NULL AND lng IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_starts_at_idx');
            $table->dropIndex('events_category_starts_at_idx');
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex('alerts_user_dismissed_idx');
            $table->dropIndex('alerts_user_type_dismissed_idx');
        });

        Schema::table('user_events', function (Blueprint $table) {
            $table->dropIndex('user_events_user_type_created_idx');
            $table->index(['user_id', 'event_type']);
            $table->index('created_at');
        });
    }
};
