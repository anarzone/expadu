<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person's "Download your data" requests: one ZIP each, built by a
     * queued job, kept 24 hours, then deleted with its file.
     */
    public function up(): void
    {
        Schema::create('account_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('queued'); // queued, ready, failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_exports');
    }
};
