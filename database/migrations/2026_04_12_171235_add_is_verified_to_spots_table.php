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
            $table->boolean('is_verified')->default(false)->after('source_id');
        });

        // Existing manually curated spots are verified
        DB::table('spots')->whereNull('source')->update(['is_verified' => true]);
        DB::table('spots')->where('source', 'manual')->update(['is_verified' => true]);
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('is_verified');
        });
    }
};
