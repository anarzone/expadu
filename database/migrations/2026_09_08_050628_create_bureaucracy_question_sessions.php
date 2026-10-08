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
        Schema::create('bureaucracy_question_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('bureaucracy_cases')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('jurisdiction', 80);
            $table->string('status', 30)->default('active');
            $table->unsignedInteger('consecutive_offers')->default(0);
            $table->unsignedInteger('offered_count')->default(0);
            $table->unsignedInteger('answered_count')->default(0);
            $table->unsignedInteger('deferred_count')->default(0);
            $table->unsignedBigInteger('known_fact_revision')->default(1);
            $table->jsonb('deferred')->default('{}');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['actor_id', 'request_id']);
        });
        Schema::table('bureaucracy_case_questions', function (Blueprint $table) {
            $table->foreignId('session_id')->nullable()->constrained('bureaucracy_question_sessions')->cascadeOnDelete();
            $table->uuid('request_id')->nullable();
            $table->string('protocol_version', 80)->nullable();
            $table->string('dependency_token', 64)->nullable();
            $table->unsignedBigInteger('fact_revision')->nullable();
            $table->text('offer_token')->nullable();
            $table->timestampTz('offer_expires_at')->nullable();
            $table->unsignedSmallInteger('malformed_attempts')->default(0);
            $table->string('answer_fingerprint', 64)->nullable();
            $table->foreignId('answer_fact_id')->nullable()->constrained('bureaucracy_case_facts')->nullOnDelete();
            $table->unsignedBigInteger('answer_fact_revision')->nullable();
            $table->unique(['session_id', 'request_id']);
        });
        Schema::create('bureaucracy_question_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('bureaucracy_question_sessions')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->foreignId('question_id')->nullable()->constrained('bureaucracy_case_questions')->cascadeOnDelete();
            $table->string('response_status', 30);
            $table->unique(['session_id', 'request_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_question_requests');
        Schema::table('bureaucracy_case_questions', function (Blueprint $table) {
            $table->dropUnique(['session_id', 'request_id']);
            $table->dropConstrainedForeignId('answer_fact_id');
            $table->dropConstrainedForeignId('session_id');
            $table->dropColumn(['request_id', 'protocol_version', 'dependency_token', 'fact_revision', 'offer_token', 'offer_expires_at', 'malformed_attempts', 'answer_fingerprint', 'answer_fact_revision']);
        });
        Schema::dropIfExists('bureaucracy_question_sessions');
    }
};
