<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which of App\Personalisation\Sensitive::KINDS made an event sensitive, so every mark can be
        // checked; null when it isn't. `sensitive` stays the flag the ledger filters on.
        Schema::table('events', function (Blueprint $table) {
            $table->string('sensitive_kind', 16)->nullable()->after('sensitive');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('sensitive_kind');
        });
    }
};
