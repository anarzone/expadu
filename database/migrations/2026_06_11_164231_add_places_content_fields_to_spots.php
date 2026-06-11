<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Places page editorial fields. Most leisure spots are OSM-seeded with no
 * hours/photos; these nullable columns let us curate a per-place photo and
 * "tip" later. Until curated, the PlaceResource falls back to a friendly
 * category illustration and a category-level tip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->text('tip')->nullable();
            $table->string('photo_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn(['tip', 'photo_url']);
        });
    }
};
