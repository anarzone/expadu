<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a place's point was looked up from its typed address rather
     * than picked from a suggestion or the person's location.
     */
    public function up(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->boolean('located_from_address')->default(false)->after('lng');
        });
    }

    public function down(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->dropColumn('located_from_address');
        });
    }
};
