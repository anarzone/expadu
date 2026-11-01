<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materialised, model-ready features for places (rebuilt nightly from
 * PlaceFacts by places:build-features), a history of event classifications
 * with the model and confidence that produced them, and the OSM cuisine tag
 * copied into spots.cuisine, which nothing filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_features', function (Blueprint $table): void {
            $table->foreignId('spot_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('interests');
            $table->json('activities');
            $table->string('setting', 16)->nullable();
            $table->boolean('kid_friendly');
            $table->string('price', 16)->nullable();
            $table->boolean('hours_known');
            $table->json('hours')->nullable();
            $table->string('wheelchair', 16)->nullable();
            $table->decimal('completeness', 4, 3);
            $table->decimal('quality', 4, 3);
            // Filled by the Tier-1 enrichment batch (EXP-12) once it exists.
            $table->json('ai_tags')->nullable();
            $table->unsignedBigInteger('fact_revision');
            $table->timestamp('computed_at');
        });

        Schema::create('event_enrichments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('producer', 50);
            $table->string('model', 120)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->decimal('relevance', 4, 3)->nullable();
            $table->string('category', 40)->nullable();
            $table->json('chips');
            $table->json('interests');
            $table->boolean('needs_review');
            $table->string('input_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['event_id', 'created_at']);
        });

        DB::statement("UPDATE spots SET cuisine = left(tags->>'cuisine', 255) WHERE cuisine IS NULL AND tags->>'cuisine' IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('event_enrichments');
        Schema::dropIfExists('place_features');
    }
};
