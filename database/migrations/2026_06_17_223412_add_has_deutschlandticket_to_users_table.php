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
        Schema::table('users', function (Blueprint $table) {
            // Journey-aware fare advice: a held Deutschlandticket means
            // "covered" instead of a per-trip single ticket.
            $table->boolean('has_deutschlandticket')->default(false)->after('is_eu');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('has_deutschlandticket');
        });
    }
};
