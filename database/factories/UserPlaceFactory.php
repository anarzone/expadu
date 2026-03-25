<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPlace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserPlace> */
class UserPlaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'emoji' => fake()->randomElement(['🏠', '💼', '📚', '⭐', '🏋️']),
            'name' => fake()->randomElement(['Home', 'Work', 'Gym', 'Café', 'University']),
            'address' => fake()->address(),
            'lat' => fake()->latitude(50.9, 51.0),
            'lng' => fake()->longitude(6.9, 7.0),
            'sort_order' => 0,
        ];
    }
}
