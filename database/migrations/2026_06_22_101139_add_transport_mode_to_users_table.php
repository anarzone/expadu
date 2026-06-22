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
            // Default way of getting around: transit | bike | walk.
            // Null = "fastest realistic" (see App\Transit\TravelTimes).
            $table->string('transport_mode')->nullable()->after('has_deutschlandticket');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('transport_mode');
        });
    }
};
