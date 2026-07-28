<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->foreignId('parent_spot_id')
                ->nullable()
                ->constrained('spots')
                ->nullOnDelete()
                ->index();
        });

        Schema::table('park_areas', function (Blueprint $table) {
            // Kept under the existing table name for a non-breaking rollout.
            // An area can now be either a park or a named sports centre.
            $table->string('kind', 30)->default('park')->index();
            $table->string('source_id', 50)->nullable()->unique();
            $table->foreignId('parent_spot_id')
                ->nullable()
                ->constrained('spots')
                ->nullOnDelete()
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('park_areas', function (Blueprint $table) {
            $table->dropForeign(['parent_spot_id']);
            $table->dropIndex(['parent_spot_id']);
            $table->dropUnique(['source_id']);
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'source_id', 'parent_spot_id']);
        });

        Schema::table('spots', function (Blueprint $table) {
            $table->dropForeign(['parent_spot_id']);
            $table->dropIndex(['parent_spot_id']);
            $table->dropColumn('parent_spot_id');
        });
    }
};
