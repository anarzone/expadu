<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemetry for the AI features: what each LLM call cost and how it ended, and
 * how each Composer plan was ranked, so LLM on and off can be compared.
 *
 * llm_calls holds no user link and no prompt or response text. composer_runs
 * is linked to the user (deleted with the account) but stores categories and
 * scores only, never place or event ids. How long either is kept is the
 * retention decision in EXP-117.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('feature', 64);
            $table->string('model', 120)->nullable();
            $table->string('prompt_version', 40)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->string('outcome', 120);
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['feature', 'created_at']);
        });

        Schema::create('composer_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ranker_mode', 32);
            $table->boolean('llm_bucket');
            $table->unsignedSmallInteger('candidate_count');
            $table->unsignedSmallInteger('slot_count');
            $table->json('slots');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composer_runs');
        Schema::dropIfExists('llm_calls');
    }
};
