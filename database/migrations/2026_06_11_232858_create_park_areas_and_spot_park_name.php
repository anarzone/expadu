<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Park polygons from OSM (parks:import-areas) give facilities a venue:
 * a pitch inside Blücherpark carries park_name "Blücherpark" via
 * ST_Contains — the precise answer to "are these in the same park?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('park_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->unsignedBigInteger('osm_id')->unique();
            $table->timestamps();
        });

        // Polygon column via raw SQL — Eloquent doesn't speak PostGIS.
        // Geometry (not MultiPolygon) so convex-hull fallbacks fit too.
        DB::statement('ALTER TABLE park_areas ADD COLUMN boundary geometry(Geometry, 4326)');
        DB::statement('CREATE INDEX park_areas_boundary_gist ON park_areas USING GIST (boundary)');

        Schema::table('spots', function (Blueprint $table) {
            $table->string('park_name', 150)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('park_areas');
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('park_name');
        });
    }
};
