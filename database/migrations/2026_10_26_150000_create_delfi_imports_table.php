<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gtfs.de timetable delfi_calls was imported from, kept beside it (one
 * row): its realtime feed numbers trips by gtfs.de's newest timetable, so
 * it's read only while the two match, and a lost cache must not forget it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delfi_imports', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->timestamp('imported_at');
        });

        // What the cache still knows of the last import, so live reads go on
        // without waiting for a re-import of an unchanged timetable.
        $kept = Cache::get('delfi:static_version');
        if (is_array($kept) && DB::table('delfi_calls')->exists()) {
            DB::table('delfi_imports')->insert(['id' => 1, 'etag' => $kept['etag'] ?? null, 'last_modified' => $kept['last_modified'] ?? null, 'imported_at' => now()]);
        }
        Cache::forget('delfi:static_version');
    }

    public function down(): void
    {
        Schema::dropIfExists('delfi_imports');
    }
};
