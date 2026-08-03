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
        Schema::create('bureaucracy_case_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('bureaucracy_cases')->cascadeOnDelete();
            $table->string('fact_key');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamp('asked_at');
            $table->timestamp('answered_at')->nullable();
            $table->string('outcome', 50)->nullable();
            $table->timestamps();

            $table->index(['case_id', 'fact_key', 'outcome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_case_questions');
    }
};
