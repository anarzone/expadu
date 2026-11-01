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
        Schema::create('bureaucracy_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('bureaucracy_cases')->cascadeOnDelete();
            $table->string('definition_id', 100);
            $table->string('topic', 30);
            $table->string('jurisdiction', 80);
            $table->string('occurrence_key', 64);
            $table->string('context_id', 100);
            $table->string('catalogue_hash', 64);
            $table->unsignedBigInteger('version')->default(1);
            $table->text('state');
            $table->jsonb('step_definitions');
            $table->timestampsTz();
            $table->unique(['case_id', 'definition_id', 'jurisdiction', 'occurrence_key'], 'bureaucracy_process_occurrence_unique');
        });
        Schema::create('bureaucracy_process_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained('bureaucracy_processes')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id');
            $table->string('request_fingerprint', 64);
            $table->string('type', 40);
            $table->text('payload');
            $table->foreignId('corrects_event_id')->nullable()->constrained('bureaucracy_process_events')->nullOnDelete();
            $table->unsignedBigInteger('process_version');
            $table->timestampTz('recorded_at');
            $table->unique(['process_id', 'request_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_process_events');
        Schema::dropIfExists('bureaucracy_processes');
    }
};
