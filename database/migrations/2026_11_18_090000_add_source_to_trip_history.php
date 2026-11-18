<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a kept trip came from: started in the app, or noticed by the phone leaving Home or
     * Work (LeaveNotice), so turning noticing off erases only those.
     */
    public function up(): void
    {
        Schema::table('trip_history', function (Blueprint $table) {
            $table->string('source', 8)->default('app');
        });
    }

    public function down(): void
    {
        Schema::table('trip_history', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
