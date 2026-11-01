<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The VRS timetable feed last imported (one row), kept in the database: in
 * the cache, a lost key made gtfs:health call a healthy feed stale and
 * gtfs:refresh re-import an unchanged one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gtfs_imports', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->timestamp('imported_at');
        });

        // What the cache still knows of the last import.
        $kept = Cache::get('gtfs:feed_version');
        if (is_array($kept) && DB::table('gtfs_trips')->exists()) {
            DB::table('gtfs_imports')->insert([
                'id' => 1,
                'etag' => $kept['etag'] ?? null,
                'last_modified' => $kept['last_modified'] ?? null,
                'imported_at' => isset($kept['imported_at']) ? Carbon::parse($kept['imported_at']) : now(),
            ]);
        }
        Cache::forget('gtfs:feed_version');
    }

    public function down(): void
    {
        Schema::dropIfExists('gtfs_imports');
    }
};
