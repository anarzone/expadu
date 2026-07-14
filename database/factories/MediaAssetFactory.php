<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $providerAssetId = fake()->uuid();

        return [
            'type' => 'image',
            'provider' => 'test-provider',
            'provider_asset_id' => $providerAssetId,
            'source_key' => hash('sha256', 'test-provider|'.$providerAssetId),
            'remote_url' => fake()->imageUrl(1200, 800),
            'rights_status' => 'pending',
            'health_status' => 'pending',
            'last_seen_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'rights_status' => 'approved',
            'health_status' => 'active',
            'license_code' => 'CC BY 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
            'last_verified_at' => now(),
        ]);
    }
}
