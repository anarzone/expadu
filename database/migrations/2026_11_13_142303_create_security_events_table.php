<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The security log (App\Security\SecurityLog): sign-ups, sign-ins, failed
 * sign-ins, lockouts, password resets, verifications and what the bot traps
 * and the email budget turned away. Kept 90 days (model:prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('channel', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Only the part after the @: enough to see a flood from one
            // throwaway service, without keeping strangers' addresses.
            $table->string('email_domain')->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['type', 'created_at']);
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
