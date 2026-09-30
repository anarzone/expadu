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
        Schema::create('media_validation_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->char('input_fingerprint', 64);
            $table->string('remote_url', 2048);
            $table->char('checksum', 64)->nullable();
            $table->timestampTz('asset_updated_at')->nullable();
            $table->string('outcome', 40);
            $table->string('error_code', 120)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            $table->jsonb('metadata')->nullable();

            $table->index(['media_asset_id', 'started_at']);
            $table->index(['outcome', 'finished_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_validation_attempts');
    }
};
