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
        Schema::create('bureaucracy_case_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('bureaucracy_cases')->cascadeOnDelete();
            $table->string('key')->index();
            $table->jsonb('value');
            $table->string('state', 20)->index();
            $table->string('source', 100);
            $table->string('source_reference', 2048)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('reconfirm_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'key', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_case_facts');
    }
};
