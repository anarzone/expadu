<?php

namespace Database\Factories;

use App\Models\PlaceFactCorrection;
use App\Models\Spot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceFactCorrection>
 */
class PlaceFactCorrectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'spot_id' => Spot::factory(),
            'field' => 'name',
            'value' => ['value' => fake()->company()],
            'evidence' => fake()->sentence(10),
            'evidence_url' => 'https://example.test/evidence',
            'actor' => fake()->email(),
            'reviewed_at' => now(),
            'supersedes_id' => null,
            'revoked_at' => null,
        ];
    }
}
