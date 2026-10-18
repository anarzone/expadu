<?php

use App\Places\PlaceFacts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store whether the access rule lets a place into public recommendations,
 * instead of recomputing the rule over every observation inside every place
 * query. New rows start hidden until their flag is computed; the backfill
 * computes every existing place now, so nothing changes visibly on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table): void {
            $table->boolean('public_access_allowed')->default(false);
        });

        app(PlaceFacts::class)->refreshAllPublicAccess();
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table): void {
            $table->dropColumn('public_access_allowed');
        });
    }
};
