<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin's People pages (plan C): an account can be suspended (it can't
 * sign in anywhere until it's lifted), and an account or waitlist entry the
 * admin looked at and kept leaves the "Needs a look" queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
        Schema::table('waitlist_signups', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'reviewed_at']);
        });
        Schema::table('waitlist_signups', function (Blueprint $table) {
            $table->dropColumn('reviewed_at');
        });
    }
};
