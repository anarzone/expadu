<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // 'task' = actionable checklist item; 'info' = good-to-know card
            // (no checkbox, excluded from progress %).
            $table->string('type')->default('task')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            // Fine-grained path refinement chosen on the bureaucracy page
            // (e.g. non_eu_employee_blue_card). Null = unrefined, use the
            // situation-derived branch.
            $table->string('bureaucracy_path')->nullable();
        });

        Schema::table('user_tasks', function (Blueprint $table) {
            // Per-document checklist state: list of checked document strings.
            $table->json('documents_checked')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bureaucracy_path');
        });

        Schema::table('user_tasks', function (Blueprint $table) {
            $table->dropColumn('documents_checked');
        });
    }
};
