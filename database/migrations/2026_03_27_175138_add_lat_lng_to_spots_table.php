<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('address');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });

        // Back-fill lat/lng from existing PostGIS location column
        DB::statement('UPDATE spots SET lat = ST_Y(location::geometry), lng = ST_X(location::geometry) WHERE location IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
};
