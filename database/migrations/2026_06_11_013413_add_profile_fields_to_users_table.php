<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 profile engine: the user's home Veedel (Cologne Stadtteil) drives
 * default content areas; is_eu disambiguates situations where citizenship
 * isn't implied (student, freelancer, other).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('veedel', 100)->nullable()->index()->after('city');
            $table->boolean('is_eu')->nullable()->after('situation');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['veedel', 'is_eu']);
        });
    }
};
