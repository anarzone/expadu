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
        Schema::table('tasks', function (Blueprint $table) {
            // Source quotes that back each figure on an automatically checked card.
            $table->json('claims')->nullable();
        });

        Schema::create('bureaucracy_source_checks', function (Blueprint $table) {
            $table->id();
            $table->string('task_key')->unique();
            $table->string('check_hash', 64);
            $table->string('outcome', 16);
            $table->json('failures');
            $table->json('unreachable');
            $table->timestampTz('checked_at');
            // The last content that passed, when it first passed, and how long that pass counts.
            $table->string('passed_hash', 64)->nullable();
            $table->date('first_passed_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestampsTz();
        });

        Schema::create('bureaucracy_escalations', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 48);
            $table->string('subject', 191);
            $table->string('severity', 16);
            $table->text('summary');
            $table->json('details')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('alerted_at')->nullable();
            $table->timestampTz('resolved_at')->nullable()->index();
            $table->timestampsTz();
            $table->unique(['kind', 'subject']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_escalations');
        Schema::dropIfExists('bureaucracy_source_checks');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('claims');
        });
    }
};
