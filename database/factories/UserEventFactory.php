<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserEvent> */
class UserEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => fake()->randomElement(['page_viewed', 'card_tapped', 'task_completed', 'spot_checked_in']),
            'payload' => ['page' => 'home'],
            'session_id' => fake()->uuid(),
        ];
    }
}
