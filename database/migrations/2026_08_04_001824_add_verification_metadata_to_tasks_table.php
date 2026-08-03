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
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('jurisdiction')->nullable();
            $table->json('legal_sources')->nullable();
            $table->string('review_status', 20)->default('legacy')->index();
            $table->string('source_verification', 40)->nullable();
            $table->string('reviewed_by')->nullable();
            $table->string('content_version')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->date('review_due_at')->nullable()->index();
            $table->json('conflicts_with')->nullable();
            $table->string('coverage_scope', 20)->default('case');
            $table->string('deadline_fact_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'jurisdiction',
                'legal_sources',
                'review_status',
                'source_verification',
                'reviewed_by',
                'content_version',
                'effective_from',
                'effective_to',
                'review_due_at',
                'conflicts_with',
                'coverage_scope',
                'deadline_fact_key',
            ]);
        });
    }
};
