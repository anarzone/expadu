<?php

namespace Database\Factories;

use App\Models\Routine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Routine> */
class RoutineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Morning Commute', 'Evening Return', 'University', 'Gym Route']),
            'emoji' => fake()->randomElement(['🏠→💼', '💼→🏠', '🏠→📚', '🏠→🏋️']),
            'from_stop' => fake()->randomElement(['Ehrenfeld', 'Neumarkt', 'Köln Hbf', 'Venloer Str./Gürtel']),
            'to_stop' => fake()->randomElement(['Mediapark', 'Deutz', 'Universität', 'Köln Messe/Deutz']),
            'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'departure_time' => '08:15',
            'is_active' => true,
        ];
    }
}
