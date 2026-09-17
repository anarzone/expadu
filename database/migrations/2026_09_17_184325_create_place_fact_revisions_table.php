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
        Schema::create('place_fact_revisions', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });

        DB::table('place_fact_revisions')->insert(['id' => 1, 'revision' => 0]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('place_fact_revisions');
    }
};
