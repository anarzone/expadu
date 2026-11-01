<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notice's English text is its brief, or, when no brief passed the
 * checks, a checked literal translation ("Translated from German").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notice_briefs', function (Blueprint $table) {
            $table->string('kind', 16)->default('brief')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('notice_briefs', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
