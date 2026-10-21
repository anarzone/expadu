<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence-backed vibe tags on the English place enrichment (R2d), and the
 * Message Batches the enricher has submitted and not yet collected.
 *
 * vibe_tags: [{tag, by: rule|model, evidence: {source: osm|text, ref}}].
 * summary_status says why `summary` is what it is: ok (written and passed
 * the linter), rejected (the linter threw it away, so it is null) or
 * unknown (too little source data to describe, so no model call was made).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spot_enrichments', function (Blueprint $table): void {
            $table->jsonb('vibe_tags')->nullable();
            $table->string('summary_status', 16)->nullable();
        });

        // Retrieval asks "which places carry tag X": jsonb containment.
        DB::statement('CREATE INDEX spot_enrichments_vibe_tags_gin ON spot_enrichments USING gin (vibe_tags jsonb_path_ops)');

        Schema::create('spot_enrichment_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_batch_id', 120)->unique();
            $table->string('status', 20)->default('in_progress')->index();
            $table->unsignedInteger('request_count');
            $table->jsonb('spot_ids');
            $table->decimal('estimated_cost_usd', 12, 6);
            $table->string('model', 120);
            $table->string('prompt_version', 40);
            $table->json('result_counts')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_enrichment_batches');
        DB::statement('DROP INDEX IF EXISTS spot_enrichments_vibe_tags_gin');
        Schema::table('spot_enrichments', function (Blueprint $table): void {
            $table->dropColumn(['vibe_tags', 'summary_status']);
        });
    }
};
