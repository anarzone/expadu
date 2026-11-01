<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bureaucracy_onboarding_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('bureaucracy_people')->cascadeOnDelete();
            $table->string('schema_version', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 24)->default('active');
            $table->text('payload')->nullable();
            $table->timestampTz('expires_at')->index();
            $table->uuid('completion_request_id')->nullable();
            $table->unsignedBigInteger('completed_fact_revision')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_id', 'completion_request_id'], 'bureaucracy_draft_completion_request_unique');
        });
        DB::statement("CREATE UNIQUE INDEX bureaucracy_draft_active_actor_person ON bureaucracy_onboarding_drafts (actor_id, person_id) WHERE status = 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_onboarding_drafts');
    }
};
