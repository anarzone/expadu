<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Recurring task scheduling — null = one-shot, integer = repeat every N months
            $table->integer('recurrence_months')->nullable()->after('deadline_days');

            // Step-by-step how-to content authored per task. Shape:
            //   [{ "title": "Book appointment", "body": "...", "link": "..." }, ...]
            $table->jsonb('how_to_steps')->nullable()->after('documents_required');

            // Optional FK-style key into BuergeramtService::SERVICES catalogue.
            // When set, the frontend shows a "Book appointment" CTA that deep-links
            // to the official booking flow with the matching service pre-selected.
            $table->string('booking_service_key')->nullable()->after('how_to_steps');
        });

        Schema::table('user_tasks', function (Blueprint $table) {
            // Framing-B status pipeline. See App\Enums\TaskStatus for semantics.
            $table->string('status')->default('not_started')->after('task_id');

            // Recurrence tracking: when this user_task's next instance becomes
            // relevant. Set by the model on completion of a recurring task.
            $table->timestamp('next_due_at')->nullable()->after('completed_at');

            // User-managed opt-out — e.g. "I'm not getting married, hide marriage tasks."
            // Different from done; preserves the task in the catalogue but pulls it
            // out of the active stack.
            $table->boolean('is_applicable')->default(true)->after('next_due_at');

            $table->index(['user_id', 'status'], 'user_tasks_user_status_idx');
            $table->index(['user_id', 'is_applicable'], 'user_tasks_user_applicable_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_tasks', function (Blueprint $table) {
            $table->dropIndex('user_tasks_user_status_idx');
            $table->dropIndex('user_tasks_user_applicable_idx');
            $table->dropColumn(['status', 'next_due_at', 'is_applicable']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['recurrence_months', 'how_to_steps', 'booking_service_key']);
        });
    }
};
