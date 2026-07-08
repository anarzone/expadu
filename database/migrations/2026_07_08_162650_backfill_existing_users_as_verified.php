<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grandfather every existing account as verified the moment email
     * verification goes live (User now implements MustVerifyEmail, which
     * activates the `verified` route middleware app-wide). Without this,
     * current users with a null email_verified_at would be bounced to the
     * verify-email wall on their next visit. New sign-ups still verify.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Irreversible data backfill — there is no safe way to know which rows
     * were unverified beforehand, so the down migration is intentionally a
     * no-op.
     */
    public function down(): void
    {
        // no-op
    }
};
