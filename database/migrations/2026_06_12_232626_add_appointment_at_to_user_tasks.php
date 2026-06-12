<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_tasks', function (Blueprint $table) {
            // The user's booked office appointment. Once set it becomes the
            // task's effective deadline (reminders fire off it) and feeds
            // take-me-there's arrive-by routing.
            $table->dateTime('appointment_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_tasks', function (Blueprint $table) {
            $table->dropColumn('appointment_at');
        });
    }
};
