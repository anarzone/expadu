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
        Schema::table('media_assets', function (Blueprint $table) {
            $table->timestampTz('next_validation_at')->nullable();
            $table->timestampTz('validation_queued_at')->nullable();
            $table->char('validation_queued_fingerprint', 64)->nullable();
            $table->string('last_validation_outcome', 40)->nullable();
            $table->string('last_validation_error_code', 120)->nullable();

            $table->index(
                ['next_validation_at', 'id'],
                'media_assets_validation_due_lookup',
            );
            $table->index(
                ['validation_queued_at', 'id'],
                'media_assets_validation_queue_lookup',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex('media_assets_validation_due_lookup');
            $table->dropIndex('media_assets_validation_queue_lookup');
            $table->dropColumn([
                'next_validation_at',
                'validation_queued_at',
                'validation_queued_fingerprint',
                'last_validation_outcome',
                'last_validation_error_code',
            ]);
        });
    }
};
