<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->string('source_group', 50)->nullable()->after('source_id')->index();
            $table->timestamp('last_seen_at')->nullable()->after('source_group')->index();
            $table->boolean('is_active')->default(true)->after('last_seen_at')->index();
            $table->boolean('is_recommendable')->default(true)->after('is_active')->index();
        });

        DB::table('spots')
            ->whereRaw("name ~* '^(Spielplatz|Bolzplatz|Basketballplatz|Tennisplatz|Tischtennisplatte|Boulebahn|Skatepark|Hundewiese|Grillplatz|Picknickplatz)(\\s*·.*)?$'")
            ->update(['is_recommendable' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn(['source_group', 'last_seen_at', 'is_active', 'is_recommendable']);
        });
    }
};
