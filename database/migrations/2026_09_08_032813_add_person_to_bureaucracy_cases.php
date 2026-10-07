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
        Schema::table('bureaucracy_cases', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->unique()->constrained('bureaucracy_people')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('bureaucracy_cases')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Cannot roll back people while dependent dossiers exist; use a reviewed forward migration.');
        }
        Schema::table('bureaucracy_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
