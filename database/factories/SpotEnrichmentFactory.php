<?php

namespace Database\Factories;

use App\Models\Spot;
use App\Models\SpotEnrichment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpotEnrichment>
 */
class SpotEnrichmentFactory extends Factory
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
            'locale' => 'en',
            'status' => 'ready',
            'needs_review' => true,
            'summary' => fake()->sentence(),
            'tip' => null,
            'source_snapshot' => ['name' => fake()->company(), 'category' => 'park'],
            'source_hash' => hash('sha256', fake()->uuid()),
            'prompt_version' => 'place-summary-v1',
            'producer' => 'local-codex-agent',
            'model' => 'local-worker',
            'generated_at' => now(),
        ];
    }
}
