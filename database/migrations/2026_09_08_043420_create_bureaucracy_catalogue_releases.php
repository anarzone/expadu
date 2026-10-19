<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bureaucracy_catalogue_releases', function (Blueprint $table) {
            $table->id();
            $table->string('content_hash', 64)->unique();
            $table->string('schema_version', 80);
            $table->jsonb('artifact');
            $table->timestampsTz();
        });
        Schema::create('bureaucracy_catalogue_pointers', function (Blueprint $table) {
            $table->string('name', 40)->primary();
            $table->foreignId('release_id')->nullable()->constrained('bureaucracy_catalogue_releases')->restrictOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::table('bureaucracy_catalogue_pointers')->insert(['name' => 'active', 'version' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_catalogue_pointers');
        Schema::dropIfExists('bureaucracy_catalogue_releases');
    }
};
