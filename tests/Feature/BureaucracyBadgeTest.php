<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

/**
 * Attach a user_task in a specific state so the attention count can be
 * exercised one facet at a time. Tasks are days-since-arrival by default
 * (TaskFactory), so deadline = arrival_date + deadline_days.
 */
function attachUserTask(User $user, array $taskAttributes, array $userTaskAttributes): void
{
    $task = Task::factory()->create($taskAttributes);
    UserTask::factory()->create([
        'user_id' => $user->id,
        'task_id' => $task->id,
        ...$userTaskAttributes,
    ]);
}

test('bureaucracy badge counts only open action tasks that are overdue or due within two weeks', function () {
    // Arrived 10 days ago: deadline = arrival + deadline_days.
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(10)->toDateString()]);

    $open = ['status' => TaskStatus::NotStarted->value, 'is_applicable' => true];

    // Overdue (arrival + 5 = 5 days ago) → counts.
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 5], $open);
    // Due in 5 days (arrival + 15) → within the window → counts.
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 15], $open);

    // Due in 80 days (arrival + 90) → outside the window → not counted.
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 90], $open);
    // No deadline at all → not counted.
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_type' => 'none', 'deadline_days' => null], $open);
    // Due soon but already done → not counted.
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 3], ['status' => TaskStatus::Done->value, 'is_applicable' => true]);
    // Due soon but opted out → not counted.
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 3], ['status' => TaskStatus::NotStarted->value, 'is_applicable' => false]);
    // Due soon info card (type != task) → not counted.
    attachUserTask($user, ['is_published' => true, 'type' => 'info', 'deadline_days' => 3], $open);
    // Due soon but unpublished → not counted.
    attachUserTask($user, ['is_published' => false, 'type' => 'task', 'deadline_days' => 3], $open);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('bureaucracyAttentionCount', 2));
});

test('bureaucracy badge is zero when nothing is pressing', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(10)->toDateString()]);

    // Only a far-off task and a completed one — nothing needs attention.
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 120], ['status' => TaskStatus::NotStarted->value, 'is_applicable' => true]);
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 3], ['status' => TaskStatus::Done->value, 'is_applicable' => true]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('bureaucracyAttentionCount', 0));
});
