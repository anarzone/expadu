<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Life-event trigger: the task stays dormant until the user
            // records the named event (child_born, graduated, found_job).
            // Events are just dated profile attributes — recompute does the rest.
            $table->string('trigger_event')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('trigger_event');
        });
    }
};
