<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paperwork's "Say it in your own words" is gone (owner, 29 Oct 2026: Composer answers; Paperwork
     * has no AI helper of its own). Its suggested answers, never saved until confirmed and kept for
     * minutes, and the usage counts kept for its daily limit go with it.
     */
    public function up(): void
    {
        Schema::dropIfExists('bureaucracy_extraction_candidates');
        Schema::dropIfExists('bureaucracy_processing_legacy_usage');
    }

    public function down(): void
    {
        // One way: nothing writes either table any more.
    }
};
