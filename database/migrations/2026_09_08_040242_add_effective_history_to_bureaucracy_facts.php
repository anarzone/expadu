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
        Schema::table('bureaucracy_case_facts', function (Blueprint $table) {
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('end_date_unknown')->default(false);
            $table->string('answer_state', 24)->default('value');
            $table->string('operation', 24)->nullable();
            $table->timestampTz('recorded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('supersedes_fact_id')->nullable()->constrained('bureaucracy_case_facts')->nullOnDelete();
            $table->text('provenance')->nullable();
            $table->index(['case_id', 'key', 'effective_from'], 'bureaucracy_fact_period_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bureaucracy_case_facts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supersedes_fact_id');
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropIndex('bureaucracy_fact_period_index');
            $table->dropColumn(['effective_from', 'effective_until', 'end_date_unknown', 'answer_state', 'operation', 'recorded_at', 'provenance']);
        });
    }
};
