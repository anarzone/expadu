<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2 Places: Veedel is the primary navigation. spots.veedel is assigned
 * by spots:assign-veedel from the veedels polygon table (Cologne open
 * data Stadtteile); nearest-centroid is the fallback when polygons are
 * unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->string('veedel', 100)->nullable()->index();
        });

        Schema::create('veedels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('bezirk', 100);
            $table->decimal('centroid_lat', 10, 7)->nullable();
            $table->decimal('centroid_lng', 10, 7)->nullable();
            $table->timestamps();
        });

        // Polygon column via raw SQL — Eloquent doesn't speak PostGIS.
        DB::statement('ALTER TABLE veedels ADD COLUMN boundary geometry(MultiPolygon, 4326)');
        DB::statement('CREATE INDEX veedels_boundary_gist ON veedels USING GIST (boundary)');
    }

    public function down(): void
    {
        Schema::dropIfExists('veedels');
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('veedel');
        });
    }
};
