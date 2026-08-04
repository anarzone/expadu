<?php

namespace Database\Factories;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BureaucracyCaseMessage>
 */
class BureaucracyCaseMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'case_id' => BureaucracyCase::factory(),
            'role' => 'user',
            'content' => fake()->sentence(),
            'operation' => 'extract_case_fact',
            'prompt_version' => '2026-08-04',
            'expires_at' => now()->addDays(30),
        ];
    }
}
