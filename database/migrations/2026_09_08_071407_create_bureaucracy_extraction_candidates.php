<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bureaucracy_extraction_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consent_id')->unique()->constrained('bureaucracy_processing_consents')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('bureaucracy_cases')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('bureaucracy_case_questions')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('bureaucracy_question_sessions')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('state', 30)->default('pending');
            $table->text('value')->nullable();
            $table->text('confirmation_token')->nullable();
            $table->string('dependency_token', 64);
            $table->string('authority_token', 64);
            $table->timestampTz('expires_at')->index();
            $table->foreignId('confirmed_fact_id')->nullable()->constrained('bureaucracy_case_facts')->nullOnDelete();
            $table->unsignedBigInteger('confirmed_fact_revision')->nullable();
            $table->uuid('confirmation_request_id')->nullable();
            $table->string('confirmation_fingerprint', 64)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_extraction_candidates');
    }
};
