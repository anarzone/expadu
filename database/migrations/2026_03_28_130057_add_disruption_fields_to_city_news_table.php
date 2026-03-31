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
        Schema::table('city_news', function (Blueprint $table) {
            $table->json('affected_lines')->nullable()->after('relevance');
            $table->string('severity')->nullable()->after('affected_lines');
        });
    }

    public function down(): void
    {
        Schema::table('city_news', function (Blueprint $table) {
            $table->dropColumn(['affected_lines', 'severity']);
        });
    }
};
