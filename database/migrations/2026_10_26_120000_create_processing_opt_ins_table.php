<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's standing yes to one kind of processing they turn on in Settings (AI-written day
 * briefs, owner 26 Oct 2026), bound to the notice and provider they agreed to. Each use still
 * gets its own request permission (bureaucracy_processing_consents); this only says they may.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_opt_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('notice_version', 100);
            $table->string('provider_version', 64);
            $table->timestampTz('granted_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_opt_ins');
    }
};
