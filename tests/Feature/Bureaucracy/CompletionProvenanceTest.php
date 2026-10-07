<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

/**
 * Owner review: "How did it know I completed these? I never said anything about
 * them during onboarding."
 *
 * A general settled declaration cannot prove completion of individual tasks.
 * Keep explicit user progress and its provenance unchanged.
 */
it('does not complete individual tasks from a settled declaration', function () {
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
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.anmeldung']);
    $row = UserTask::factory()->for($user)->for($task)->create(['status' => 'in_progress']);
    $this->actingAs($user)->post('/bureaucracy/settle')->assertRedirect();

    $autoCompleted = UserTask::query()
        ->where('user_id', $user->getKey())
        ->where('status', TaskStatus::Done)
        ->get();

    expect($autoCompleted)->toBeEmpty()
        ->and($row->fresh()->status)->toBe(TaskStatus::InProgress)
        ->and($row->fresh()->completed_at)->toBeNull()
        ->and($row->fresh()->completed_source)->toBeNull();
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
