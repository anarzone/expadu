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
        Schema::create('place_fact_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spot_id')->constrained('spots')->restrictOnDelete();
            $table->string('field', 50);
            $table->jsonb('value')->nullable();
            $table->text('evidence');
            $table->text('evidence_url')->nullable();
            $table->string('actor', 191);
            $table->timestampTz('reviewed_at');
            $table->foreignId('supersedes_id')->nullable()->constrained('place_fact_corrections')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(
                ['spot_id', 'field', 'revoked_at', 'reviewed_at'],
                'place_fact_correction_active_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('place_fact_corrections');
    }
};
