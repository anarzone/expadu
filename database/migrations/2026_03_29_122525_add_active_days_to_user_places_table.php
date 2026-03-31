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
            $table->string('day_mode')->default('all')->after('arrive_by'); // all, weekdays, weekends, custom
            $table->json('active_days')->nullable()->after('day_mode'); // ['mon','tue',...] for custom mode
        });
    }

    public function down(): void
    {
        Schema::table('user_places', function (Blueprint $table) {
            $table->dropColumn(['day_mode', 'active_days']);
        });
    }
};
