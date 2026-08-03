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
        Schema::create('bureaucracy_plan_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('bureaucracy_cases')->cascadeOnDelete();
            $table->unsignedInteger('fact_version');
            $table->char('rules_hash', 64);
            $table->jsonb('rule_versions');
            $table->string('coverage_state', 30);
            $table->jsonb('sections');
            $table->jsonb('unresolved_facts');
            $table->timestamp('reassessment_at')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'generated_at']);
            $table->index(['case_id', 'superseded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_plan_snapshots');
    }
};
