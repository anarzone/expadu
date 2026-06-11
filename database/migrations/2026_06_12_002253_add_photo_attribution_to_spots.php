<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Place photos come from Wikimedia Commons (CC/PD licensed) — the
 * licence requires visible credit, stored here ready to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->string('photo_attribution', 300)->nullable()->after('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('photo_attribution');
        });
    }
};
