<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

/**
 * Owner review: "How did it know I completed these? I never said anything about
 * them during onboarding."
 *
 * Declaring "I'm settled" marks every arrival basic done in one go. That is
 * defensible — someone settled here registered their address years ago — but
 * nothing recorded that the app had decided, so the page asserted finished work
 * the user never reported and could not explain itself when asked.
 */
it('records that the app, not the user, completed a task', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'bureaucracy_path' => 'non_eu_employee',
        'veedel' => 'Altstadt-Nord',
        'arrival_date' => now()->subYears(6)->toDateString(),
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);

    // Materialise the path, then declare settled.
    $this->actingAs($user)->get('/bureaucracy')->assertSuccessful();
    $this->actingAs($user)->post('/bureaucracy/settle')->assertRedirect();

    $autoCompleted = UserTask::query()
        ->where('user_id', $user->getKey())
        ->where('status', TaskStatus::Done)
        ->get();

    expect($autoCompleted)->not->toBeEmpty();

    // Every one of them can say who decided.
    foreach ($autoCompleted as $userTask) {
        expect($userTask->completed_source)->toBe('settled_declaration');
    }
});

it('leaves the source null when the user completes a task themselves', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
    $task = Task::query()->where('is_published', true)->firstOrFail();

    $userTask = UserTask::query()->create([
        'user_id' => $user->getKey(),
        'task_id' => $task->getKey(),
        'status' => TaskStatus::NotStarted,
        'is_applicable' => true,
    ]);

    $userTask->markDone();

    expect($userTask->fresh()->completed_source)->toBeNull();
});
