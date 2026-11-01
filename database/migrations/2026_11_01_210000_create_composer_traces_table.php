<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Composer answer: what it was asked, which tools it called with what, what they
 * returned and which sentences held (owner, 1 Nov 2026: "log the messages"). For finding out why
 * an answer went wrong; kept 30 days (ComposerTrace::prunable) and gone with the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composer_traces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 64)->nullable();
            $table->text('message');
            $table->string('page', 40)->nullable();
            // ai (an answer held), unchecked (it answered, nothing held), failed (no answer), unpermitted.
            $table->string('outcome', 20);
            $table->jsonb('steps');
            $table->jsonb('written')->nullable();
            $table->jsonb('kept')->nullable();
            $table->jsonb('dropped')->nullable();
            $table->jsonb('timings')->nullable();
            $table->jsonb('usage')->nullable();
            $table->string('shown', 2000)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composer_traces');
    }
};
