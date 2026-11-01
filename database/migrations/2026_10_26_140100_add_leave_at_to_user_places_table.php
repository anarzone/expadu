<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When they usually leave Work (owner, 26 Oct 2026): the evening's way home. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->time('leave_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->dropColumn('leave_at');
        });
    }
};
