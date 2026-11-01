<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School, kindergarten and education grounds from OSM (places:import-school-grounds),
 * and on each facility the grounds it stands on: a pitch on a school's grounds isn't
 * offered as public unless a source says it is (PlaceFacts::publicUse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('osm_id')->unique();
            $table->string('source_id', 64);
            $table->string('kind', 30)->index();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE school_areas ADD COLUMN boundary geometry(Geometry, 4326)');
        DB::statement('CREATE INDEX school_areas_boundary_gist ON school_areas USING GIST (boundary)');

        Schema::table('spots', function (Blueprint $table) {
            $table->string('grounds_kind', 30)->nullable();
            $table->string('grounds_name')->nullable();
            $table->string('grounds_source_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn(['grounds_kind', 'grounds_name', 'grounds_source_id']);
        });
        Schema::dropIfExists('school_areas');
    }
};
