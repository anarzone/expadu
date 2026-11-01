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
        Schema::create('bureaucracy_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 80);
            $table->string('aggregate_type', 40);
            $table->unsignedBigInteger('aggregate_id');
            $table->unsignedInteger('aggregate_version');
            $table->string('dedupe_key', 160)->unique();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->index();
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('claimed_until')->nullable();
            $table->timestampTz('delivered_at')->nullable()->index();
            $table->string('last_error_type')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_outbox_events');
    }
};
