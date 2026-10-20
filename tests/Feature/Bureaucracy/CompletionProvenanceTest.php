<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

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
