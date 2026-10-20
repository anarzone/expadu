<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bureaucracy_processing_consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bureaucracy_cases')->nullOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('bureaucracy_case_questions')->nullOnDelete();
            $table->unsignedBigInteger('fact_version')->nullable();
            $table->uuid('request_key');
            $table->string('purpose', 40);
            $table->string('provider_version', 64);
            $table->string('notice_version', 100);
            $table->string('input_digest', 64);
            $table->string('state', 20)->default('pending');
            $table->text('result')->nullable();
            $table->timestampTz('granted_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('delete_after')->index();
            $table->timestampsTz();
            $table->unique(['actor_id', 'request_key'], 'bureaucracy_processing_actor_request_unique');
            $table->index(['actor_id', 'purpose', 'attempted_at'], 'bureaucracy_processing_quota_index');
        });
        Schema::create('bureaucracy_processing_legacy_usage', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_message_id')->primary();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('attempted_at');
            $table->timestampTz('delete_after')->index();
            $table->index(['actor_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_processing_legacy_usage');
        Schema::dropIfExists('bureaucracy_processing_consents');
    }
};
