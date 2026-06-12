<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Long-tail profile attributes (entry_mode, housing_status,
            // license_country, moved_in_at, …). The typed columns (situation,
            // is_eu, arrival_date, veedel) stay; ProfileEngine merges both
            // into one flat attribute bag.
            $table->json('profile_attributes')->nullable();
        });

        // Append-only history of attribute changes — powers "why am I seeing
        // this", debugging, and later date-based eligibility math.
        Schema::create('profile_attribute_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('attribute');
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            // onboarding | teaser | banner | life_event | system
            $table->string('source')->default('system');
            $table->timestamps();
            $table->index(['user_id', 'attribute']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            // List of AND-groups over profile attributes (any group matching
            // makes the task applicable). Compiled from branch shorthand by
            // the importer; null falls back to legacy situation matching.
            $table->json('applies_if')->nullable();
            // Decision tasks render their options as comparison boxes.
            $table->json('decision_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_attributes');
        });

        Schema::dropIfExists('profile_attribute_changes');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['applies_if', 'decision_options']);
        });
    }
};
