<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Alert> */
class AlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['system', 'social', 'reminder']),
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(),
            'deep_link' => null,
            'read_at' => null,
            'dismissed_at' => null,
        ];
    }
}
