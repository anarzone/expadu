<?php

use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Support\Str;
use Tests\Support\ReviewedHomePlan;

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

    $fixture = ReviewedHomePlan::activate($user, [
        'fixture.badge.overdue' => ['deadline_days' => 5],
        'fixture.badge.soon' => ['deadline_days' => 15],
        'fixture.badge.later' => ['deadline_days' => 90],
        'fixture.badge.undated' => ['deadline_type' => 'none', 'deadline_days' => null],
        'fixture.badge.done' => ['deadline_days' => 3],
        'fixture.badge.cancelled' => ['deadline_days' => 3],
        'fixture.badge.info' => ['type' => 'info', 'deadline_days' => 3],
        'fixture.badge.unpublished' => ['is_published' => false, 'deadline_days' => 3],
        'fixture.badge.unknown' => ['applies_if' => [['german_level' => 'b1']], 'deadline_days' => 3],
    ], ['arrival_date' => now()->subDays(10)->toDateString()]);
    $processes = collect(app(ReconcileProcesses::class)->execute($user, $fixture['case']->person, 'de-nrw-cologne'))->keyBy('definition_id');
    $done = $processes['fixture.badge.done'];
    app(RecordProcessEvent::class)->execute($user, $done, 'step_completed', ['step_id' => 'fixture.badge.done.complete'],
        $done->version, (string) Str::uuid());
    $cancelled = $processes['fixture.badge.cancelled'];
    app(RecordProcessEvent::class)->execute($user, $cancelled, 'cancellation_reported', [], $cancelled->version, (string) Str::uuid());

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('bureaucracyAttentionCount', 2));
});

test('retained legacy progress alone cannot create a new plan badge', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(10)->toDateString()]);

    // Only a far-off task and a completed one — nothing needs attention.
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 120], ['status' => TaskStatus::NotStarted->value, 'is_applicable' => true]);
    attachUserTask($user, ['is_published' => true, 'type' => 'task', 'deadline_days' => 3], ['status' => TaskStatus::Done->value, 'is_applicable' => true]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('bureaucracyAttentionCount', 0));
});
