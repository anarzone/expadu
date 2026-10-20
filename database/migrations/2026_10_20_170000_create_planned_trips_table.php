<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The way a person picked for a plan on their day (Plan a trip's "Add to
 * plan"): one per plan, kept until the day is over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // The day item it leads to (a Composer slot id or a Paperwork appointment id).
            $table->string('target_id', 120);
            // The plan's title as it was (the reminder names it).
            $table->string('title', 200);
            $table->jsonb('journey');
            $table->timestampTz('depart_at');
            $table->timestampTz('arrive_at');
            $table->boolean('remind')->default(false);
            $table->timestampTz('reminded_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date', 'target_id']);
            $table->index(['remind', 'reminded_at', 'depart_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_trips');
    }
};
