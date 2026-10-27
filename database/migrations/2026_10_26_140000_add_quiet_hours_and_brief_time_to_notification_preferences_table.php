<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings › Notifications (owner, 26 Oct 2026): quiet hours, 22:00–06:00
 * unless set, and the brief's time (null: 45 minutes before the first plan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->time('quiet_from')->nullable()->default('22:00:00');
            $table->time('quiet_to')->nullable()->default('06:00:00');
            $table->time('brief_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['quiet_from', 'quiet_to', 'brief_at']);
        });
    }
};
