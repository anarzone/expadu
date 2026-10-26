<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| "Been there" is a fact about a visit, not a choice between Saved, More like
| this and Not interested: one place can be saved and been to. Been moves to
| its own columns; existing 'been' rows keep their date and rating.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spot_feedback', function (Blueprint $table) {
            $table->timestampTz('been_at')->nullable()->after('rating');
            $table->string('been_rating')->nullable()->after('been_at');
            $table->string('state')->nullable()->change();
        });

        DB::table('spot_feedback')->where('state', 'been')->update([
            'been_at' => DB::raw('updated_at'),
            'been_rating' => DB::raw('rating'),
            'state' => null,
            'rating' => null,
        ]);
    }

    public function down(): void
    {
        // A place both saved and been to keeps only "been" (one state, as before).
        DB::table('spot_feedback')->whereNotNull('been_at')->update([
            'state' => 'been',
            'rating' => DB::raw('been_rating'),
        ]);
        DB::table('spot_feedback')->whereNull('state')->delete();

        Schema::table('spot_feedback', function (Blueprint $table) {
            $table->string('state')->nullable(false)->change();
            $table->dropColumn(['been_at', 'been_rating']);
        });
    }
};
