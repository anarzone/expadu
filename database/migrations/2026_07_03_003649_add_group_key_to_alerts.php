<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            // The subject identity an alert coalesces on: repeated signals about
            // the SAME thing (a weather warning re-emitted each poll, an ongoing
            // line disruption) update one card instead of piling up new rows.
            $table->string('group_key')->nullable()->after('subtype');
            // How many times that subject has recurred while this card is live.
            $table->unsignedInteger('occurrence_count')->default(1)->after('group_key');

            $table->index(['user_id', 'group_key', 'dismissed_at'], 'alerts_user_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex('alerts_user_group_idx');
            $table->dropColumn(['group_key', 'occurrence_count']);
        });
    }
};
