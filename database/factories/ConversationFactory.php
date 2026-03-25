<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['language', 'event', 'direct']),
        ];
    }
}
