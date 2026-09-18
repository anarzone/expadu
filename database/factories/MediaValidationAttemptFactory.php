<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use App\Models\MediaValidationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaValidationAttempt>
 */
class MediaValidationAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'input_fingerprint' => hash('sha256', fake()->uuid()),
            'remote_url' => fake()->imageUrl(),
            'outcome' => 'active',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ];
    }
}
