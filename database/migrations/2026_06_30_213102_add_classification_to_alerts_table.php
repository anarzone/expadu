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
        Schema::table('alerts', function (Blueprint $table) {
            // v4 lanes/categories/severity. Nullable so existing rows keep
            // working — the read path derives them from `subtype` when null.
            $table->string('severity', 20)->nullable()->after('subtype');
            $table->string('category', 30)->nullable()->after('severity')->index();
            $table->string('lane', 20)->nullable()->after('category')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn(['severity', 'category', 'lane']);
        });
    }
};
