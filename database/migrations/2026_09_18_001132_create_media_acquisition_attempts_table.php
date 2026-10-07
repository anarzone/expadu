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
        Schema::create('media_acquisition_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->string('provider', 50);
            $table->string('strategy', 80);
            $table->char('input_fingerprint', 64);
            $table->jsonb('input_snapshot');
            $table->string('outcome', 24);
            $table->timestampTz('attempted_at');
            $table->timestampTz('next_attempt_at')->index();
            $table->string('error_code', 120)->nullable();
            $table->unsignedSmallInteger('candidate_count')->default(0);
            $table->jsonb('selected_asset_ids')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->char('active_key', 64)->nullable()->unique();

            $table->index(
                ['target_type', 'target_id', 'provider', 'strategy', 'id'],
                'media_acquisition_target_history',
            );
            $table->index(
                ['provider', 'strategy', 'next_attempt_at', 'id'],
                'media_acquisition_due_lookup',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_acquisition_attempts');
    }
};
