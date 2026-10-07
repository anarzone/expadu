<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bureaucracy_evidence_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('person_id')->constrained('bureaucracy_people')->cascadeOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('status', 20)->default('active');
            $table->text('details');
            $table->timestampsTz();
        });
        Schema::create('bureaucracy_evidence_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('evidence_id')->constrained('bureaucracy_evidence_items')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id');
            $table->string('request_fingerprint', 64);
            $table->unsignedBigInteger('evidence_version');
            $table->string('type', 30);
            $table->text('payload');
            $table->timestampTz('recorded_at');
            $table->unique(['evidence_id', 'request_id']);
        });
        Schema::create('bureaucracy_evidence_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('evidence_id')->constrained('bureaucracy_evidence_items')->cascadeOnDelete();
            $table->unsignedBigInteger('evidence_version');
            $table->foreignId('process_id')->constrained('bureaucracy_processes')->cascadeOnDelete();
            $table->string('requirement_id', 200);
            $table->string('requirement_hash', 64);
            $table->string('notice_version', 80);
            $table->foreignId('grantor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('request_id');
            $table->string('request_fingerprint', 64);
            $table->unique(['evidence_id', 'request_id']);
        });
        Schema::create('bureaucracy_requirement_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained('bureaucracy_processes')->cascadeOnDelete();
            $table->string('requirement_id', 200);
            $table->string('requirement_hash', 64);
            $table->string('status', 20)->default('confirmed');
            $table->foreignUuid('evidence_id')->nullable()->constrained('bureaucracy_evidence_items')->nullOnDelete();
            $table->unsignedBigInteger('evidence_version');
            $table->foreignId('share_id')->nullable()->constrained('bureaucracy_evidence_shares')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id');
            $table->string('request_fingerprint', 64);
            $table->unsignedBigInteger('process_version');
            $table->timestampTz('confirmed_at');
            $table->timestampTz('superseded_at')->nullable();
            $table->unique(['process_id', 'request_id']);
        });
        DB::statement('CREATE UNIQUE INDEX bureaucracy_active_requirement_use ON bureaucracy_requirement_uses (process_id, requirement_id) WHERE superseded_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_requirement_uses');
        Schema::dropIfExists('bureaucracy_evidence_shares');
        Schema::dropIfExists('bureaucracy_evidence_events');
        Schema::dropIfExists('bureaucracy_evidence_items');
    }
};
