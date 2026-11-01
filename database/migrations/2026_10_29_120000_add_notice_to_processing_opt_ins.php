<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The words of the notice a standing yes was given to, so when the notice changes the
        // person can be shown what changed before they agree again (ProcessingOptIns::paused).
        // Null for a yes given before this was kept.
        Schema::table('processing_opt_ins', function (Blueprint $table) {
            $table->jsonb('notice')->nullable()->after('provider_version');
        });
    }

    public function down(): void
    {
        Schema::table('processing_opt_ins', function (Blueprint $table) {
            $table->dropColumn('notice');
        });
    }
};
