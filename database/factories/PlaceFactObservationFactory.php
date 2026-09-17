<?php

namespace Database\Factories;

use App\Models\PlaceFactObservation;
use App\Models\Spot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlaceFactObservation>
 */
class PlaceFactObservationFactory extends Factory
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
            'provider' => 'osm',
            'provider_record_id' => 'node/'.fake()->unique()->numberBetween(1, 9999999),
            'source_url' => 'https://www.openstreetmap.org',
            'observed_at' => now(),
            'ingestion_key' => fake()->uuid(),
            'payload_hash' => hash('sha256', '[]'),
            'payload' => [],
        ];
    }
}
