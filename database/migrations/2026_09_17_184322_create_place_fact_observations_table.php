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
        Schema::create('place_fact_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spot_id')->constrained('spots')->restrictOnDelete();
            $table->string('provider', 50);
            $table->string('provider_record_id', 191);
            $table->text('source_url')->nullable();
            $table->timestampTz('observed_at');
            $table->string('ingestion_key', 191);
            $table->char('payload_hash', 64);
            $table->jsonb('payload');
            $table->string('record_kind', 20)->default('source');
            $table->foreignId('restores_observation_id')->nullable();
            $table->string('actor', 191)->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();

            $table->foreign('restores_observation_id')
                ->references('id')
                ->on('place_fact_observations')
                ->restrictOnDelete();

            $table->unique(
                ['spot_id', 'provider', 'provider_record_id', 'ingestion_key'],
                'place_fact_observation_ingestion_unique',
            );
            $table->index(
                ['spot_id', 'provider', 'provider_record_id', 'observed_at'],
                'place_fact_observation_latest_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('place_fact_observations');
    }
};
