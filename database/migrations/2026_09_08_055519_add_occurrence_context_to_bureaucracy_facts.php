<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bureaucracy_case_facts', function (Blueprint $table) {
            $table->string('context_id', 80)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bureaucracy_case_facts', function (Blueprint $table) {
            $table->dropColumn('context_id');
        });
    }
};
