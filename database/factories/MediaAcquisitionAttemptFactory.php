<?php

namespace Database\Factories;

use App\Models\MediaAcquisitionAttempt;
use App\Models\Spot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAcquisitionAttempt>
 */
class MediaAcquisitionAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_type' => Spot::class,
            'target_id' => Spot::factory(),
            'provider' => 'wikimedia-commons',
            'strategy' => 'spot_geosearch',
            'input_fingerprint' => hash('sha256', fake()->uuid()),
            'input_snapshot' => ['name' => fake()->company()],
            'outcome' => 'no_result',
            'attempted_at' => now(),
            'next_attempt_at' => now()->addDays(30),
            'candidate_count' => 0,
        ];
    }
}
