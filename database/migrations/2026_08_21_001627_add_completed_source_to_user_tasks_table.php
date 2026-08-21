<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some tasks are completed FOR the user: declaring "I'm settled" marks every
     * arrival basic done in one go. Owner review: "How did it know I completed
     * these? I never said anything about them during onboarding."
     *
     * Nothing recorded why, so the app could not explain itself. This does.
     * Null means the user marked it done themselves, which is the common case.
     */
    public function up(): void
    {
        Schema::table('user_tasks', function (Blueprint $table): void {
            $table->string('completed_source')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_tasks', function (Blueprint $table): void {
            $table->dropColumn('completed_source');
        });
    }
};
