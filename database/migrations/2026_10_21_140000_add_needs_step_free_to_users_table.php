<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The person needs step-free ways (owner decision, 21 Oct 2026):
            // Travel plans and warns for it. Off unless they turn it on.
            $table->boolean('needs_step_free')->default(false)->after('transport_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('needs_step_free');
        });
    }
};
