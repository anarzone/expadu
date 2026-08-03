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
        Schema::create('bureaucracy_fact_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('bureaucracy_cases')->cascadeOnDelete();
            $table->string('fact_key');
            $table->foreignId('existing_fact_id')->constrained('bureaucracy_case_facts');
            $table->foreignId('candidate_fact_id')->constrained('bureaucracy_case_facts');
            $table->string('status', 20)->default('unresolved')->index();
            $table->foreignId('resolved_fact_id')->nullable()->constrained('bureaucracy_case_facts');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'fact_key', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_fact_conflicts');
    }
};
