<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 events: translate once at ingest (store both languages, cache
 * forever) and curate the "show up alone, not speaking German, leave
 * with a contact" subset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('title_en', 500)->nullable();
            $table->text('description_en')->nullable();
            $table->string('source_lang', 5)->nullable();
            $table->boolean('is_curated')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['title_en', 'description_en', 'source_lang', 'is_curated']);
        });
    }
};
