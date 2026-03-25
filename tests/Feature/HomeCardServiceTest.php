<?php

use App\Models\Appointment;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Services\HomeCardService;

test('buildFeed returns expected card types for onboarded user', function () {
    $user = User::factory()->onboarded()->create();
    $service = new HomeCardService;

    $cards = $service->buildFeed($user);
    $types = array_column($cards, 'type');

    expect($types)->toContain('settlement_progress');
    expect($types)->toContain('your_places');
    expect($types)->toContain('quick_access');
});

test('blue highlight is included when user has critical tasks', function () {
    $user = User::factory()->onboarded()->create();
    $task = Task::factory()->create(['urgency' => 'critical']);
    UserTask::factory()->create([
        'user_id' => $user->id,
        'task_id' => $task->id,
        'completed_at' => null,
    ]);

    $service = new HomeCardService;
    $cards = $service->buildFeed($user);
    $types = array_column($cards, 'type');

    expect($types)->toContain('blue_highlight');
});

test('blue highlight is included when user has appointment within 48 hours', function () {
    $user = User::factory()->onboarded()->create();
    Appointment::factory()->create([
        'user_id' => $user->id,
        'scheduled_at' => now()->addHours(24),
    ]);

    $service = new HomeCardService;
    $cards = $service->buildFeed($user);
    $types = array_column($cards, 'type');

    expect($types)->toContain('blue_highlight');
});

test('blue highlight is not included when no urgent tasks or appointments', function () {
    $user = User::factory()->onboarded()->create();

    $service = new HomeCardService;
    $cards = $service->buildFeed($user);
    $types = array_column($cards, 'type');

    expect($types)->not->toContain('blue_highlight');
});

test('settlement progress is boosted for new users', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(5),
    ]);

    $service = new HomeCardService;
    $cards = $service->buildFeed($user);

    $progressCard = collect($cards)->firstWhere('type', 'settlement_progress');

    expect($progressCard['priority'])->toBe(85);
});

test('settlement progress has normal priority for established users', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(60),
    ]);

    $service = new HomeCardService;
    $cards = $service->buildFeed($user);

    $progressCard = collect($cards)->firstWhere('type', 'settlement_progress');

    expect($progressCard['priority'])->toBe(60);
});
