<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventAttendee> */
class EventAttendeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'joined_at' => now(),
            'reminded_at' => null,
        ];
    }
}
