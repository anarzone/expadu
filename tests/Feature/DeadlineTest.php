<?php

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

test('overdue task has correct deadline status', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(20)]);
    $task = Task::factory()->create(['deadline_type' => 'days_since_arrival', 'deadline_days' => 14, 'urgency' => 'critical']);
    $ut = UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);

    $status = $ut->deadline_status;

    expect($status['urgency'])->toBe('overdue');
    expect($status['days_remaining'])->toBeLessThan(0);
    expect($status['label'])->toContain('Overdue');
    expect($status['priority_boost'])->toBe(50);
});

test('critical deadline within 3 days', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(12)]);
    $task = Task::factory()->create(['deadline_type' => 'days_since_arrival', 'deadline_days' => 14, 'urgency' => 'high']);
    $ut = UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);

    $status = $ut->deadline_status;

    expect($status['urgency'])->toBe('critical');
    expect($status['days_remaining'])->toBeBetween(0, 3);
    expect($status['priority_boost'])->toBe(40);
});

test('urgent deadline within 7 days', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(25)]);
    $task = Task::factory()->create(['deadline_type' => 'days_since_arrival', 'deadline_days' => 30, 'urgency' => 'medium']);
    $ut = UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);

    $status = $ut->deadline_status;

    expect($status['urgency'])->toBe('urgent');
    expect($status['days_remaining'])->toBeBetween(4, 7);
    expect($status['priority_boost'])->toBe(25);
});

test('on track deadline beyond 14 days', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(5)]);
    $task = Task::factory()->create(['deadline_type' => 'days_since_arrival', 'deadline_days' => 90, 'urgency' => 'low']);
    $ut = UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);

    $status = $ut->deadline_status;

    expect($status['urgency'])->toBe('on_track');
    expect($status['days_remaining'])->toBeGreaterThan(14);
    expect($status['priority_boost'])->toBe(0);
});

test('no deadline for task with deadline_type none', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(5)]);
    $task = Task::factory()->create(['deadline_type' => 'none', 'deadline_days' => null, 'urgency' => 'low']);
    $ut = UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);

    $status = $ut->deadline_status;

    expect($status['urgency'])->toBe('none');
    expect($status['days_remaining'])->toBeNull();
});

test('no deadline when user has no arrival date', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => null]);
    $task = Task::factory()->create(['deadline_type' => 'days_since_arrival', 'deadline_days' => 14, 'urgency' => 'critical']);
    $ut = UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);

    expect($ut->absolute_deadline)->toBeNull();
    expect($ut->deadline_status['urgency'])->toBe('none');
});

test('task computeDeadlineFor returns correct date', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => '2026-03-01']);
    $task = Task::factory()->create(['deadline_type' => 'days_since_arrival', 'deadline_days' => 14]);

    $deadline = $task->computeDeadlineFor($user);

    expect($deadline)->not->toBeNull();
    expect($deadline->toDateString())->toBe('2026-03-15');
});

test('bureaucracy page passes deadline data', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(10),
        'situation' => 'non_eu_employee',
    ]);
    $task = Task::factory()->create([
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 14,
        'urgency' => 'critical',
        'situation' => ['non_eu_employee'],
    ]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);
    $this->actingAs($user);

    $response = $this->get(route('bureaucracy'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('bureaucracy')
        ->has('tasks.active')
        ->has('tasks.active.0.deadline')
        ->has('tasks.active.0.days_remaining')
        ->has('tasks.active.0.deadline_tier')
        ->has('progress')
    );
});

test('snoozed tasks are filtered by scope', function () {
    $user = User::factory()->onboarded()->create();
    $task = Task::factory()->create();

    // Active task
    UserTask::create(['user_id' => $user->id, 'task_id' => $task->id, 'snoozed_until' => null]);

    // Snoozed until tomorrow
    $task2 = Task::factory()->create();
    UserTask::create(['user_id' => $user->id, 'task_id' => $task2->id, 'snoozed_until' => now()->addDay()]);

    // Snooze expired
    $task3 = Task::factory()->create();
    UserTask::create(['user_id' => $user->id, 'task_id' => $task3->id, 'snoozed_until' => now()->subHour()]);

    $notSnoozed = $user->userTasks()->notSnoozed()->count();

    expect($notSnoozed)->toBe(2); // active + expired snooze
});
