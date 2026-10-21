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
        Schema::create('spot_enrichments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spot_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10)->default('en');
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('needs_review')->default(true)->index();
            $table->text('summary')->nullable();
            $table->text('tip')->nullable();
            $table->json('source_snapshot');
            $table->string('source_hash', 64)->index();
            $table->string('prompt_version', 50);
            $table->string('producer', 50);
            $table->string('model', 100)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['spot_id', 'locale']);
            $table->index(['status', 'stale_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spot_enrichments');
    }
};
