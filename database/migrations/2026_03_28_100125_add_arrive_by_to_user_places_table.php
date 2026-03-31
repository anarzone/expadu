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
        Schema::table('user_places', function (Blueprint $table) {
            $table->time('arrive_by')->nullable()->after('lng');
        });
    }

    public function down(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->dropColumn('arrive_by');
        });
    }
};
