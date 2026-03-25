<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserTask> */
class UserTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'task_id' => Task::factory(),
            'completed_at' => null,
            'snoozed_until' => null,
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => now(),
        ]);
    }
}
