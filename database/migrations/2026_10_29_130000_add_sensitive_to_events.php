<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An event mainly about religion, politics, a union, health, or sexual orientation and
        // gender identity, as the classifier reads it: personalisation never learns from it
        // (App\Personalisation\Sensitive). events:recategorise --all reads the ones listed before.
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('sensitive')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('sensitive');
        });
    }
};
