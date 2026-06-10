<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2 bureaucracy: stable keys for YAML upserts, a dependency DAG
 * (depends_on = array of task keys), human-verified freshness
 * (verified_at is set by the owner after checking official sources,
 * never automatically), and the report-outdated counter. eu_only /
 * non_eu_only let one branch file serve both citizenship variants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('key', 120)->nullable()->unique()->after('id');
            $table->json('depends_on')->nullable()->after('phase');
            $table->timestamp('verified_at')->nullable()->after('booking_service_key');
            $table->unsignedInteger('outdated_reports')->default(0)->after('verified_at');
            $table->string('eu_filter', 20)->nullable()->after('situation'); // eu_only | non_eu_only | null
            $table->boolean('is_published')->default(true)->after('outdated_reports');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['key', 'depends_on', 'verified_at', 'outdated_reports', 'eu_filter', 'is_published']);
        });
    }
};
