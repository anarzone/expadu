<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres doesn't index foreign keys on its own. These columns are read on
 * hot paths (user_places on every Today, Places, Travel and Composer load)
 * or joined on, and had no index leading with them. The stop_times stop_id
 * index is a prefix of (stop_id, departure_time), which serves the same
 * lookups.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $indexes = [
        'user_places' => ['user_id'],
        'spots' => ['destination_spot_id', 'parent_spot_id'],
        'spot_feedback' => ['spot_id'],
        'events' => ['venue_id'],
        'event_attendees' => ['user_id'],
        'user_tasks' => ['task_id'],
        'event_reminders' => ['event_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }

        Schema::table('gtfs_stop_times', function (Blueprint $table): void {
            $table->dropIndex(['stop_id']);
        });
    }

    public function down(): void
    {
        Schema::table('gtfs_stop_times', function (Blueprint $table): void {
            $table->index('stop_id');
        });

        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }
    }
};
