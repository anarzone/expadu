<?php

namespace Database\Factories;

use App\Models\Spot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Spot> */
class SpotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'category' => fake()->randomElement(['cafe', 'coworking', 'library', 'park', 'restaurant', 'fast_food', 'bar', 'bakery']),
            'description' => fake()->sentence(),
            'address' => fake()->address(),
            'wifi_speed' => fake()->randomElement(['fast', 'ok', null]),
            'noise_level' => fake()->randomElement(['quiet', 'moderate', 'loud']),
            'time_limit_mins' => fake()->randomElement([null, 90, 120, 180]),
            'opening_hours' => null,
            'rating' => fake()->randomFloat(2, 3.0, 5.0),
        ];
    }
}
