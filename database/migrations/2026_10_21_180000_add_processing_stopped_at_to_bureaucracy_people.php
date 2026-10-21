<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop processing (GDPR Art. 18, EXP-180): when the record holder stopped
 * Paperwork using their record. Null while it's processed as usual. The
 * record stays 'active': kept and visible to its holder, used by nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureaucracy_people', function (Blueprint $table) {
            $table->timestampTz('processing_stopped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bureaucracy_people', function (Blueprint $table) {
            $table->dropColumn('processing_stopped_at');
        });
    }
};
