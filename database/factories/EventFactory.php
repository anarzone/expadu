<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'emoji' => fake()->randomElement(['🗣️', '🎵', '🏃', '🍕', '📚', '🎭']),
            'category' => fake()->randomElement(['language', 'social', 'sports', 'culture', 'food']),
            'description' => fake()->paragraph(),
            'starts_at' => fake()->dateTimeBetween('now', '+14 days'),
            'ends_at' => fake()->dateTimeBetween('+14 days', '+15 days'),
            'location_name' => fake()->company(),
            'address' => fake()->address(),
            'max_attendees' => fake()->randomElement([null, 20, 30, 50]),
            'is_free' => fake()->boolean(70),
            'price' => null,
            // Visible by default — curation tests override explicitly
            'relevance' => 0.8,
            'organiser_id' => User::factory(),
        ];
    }
}
