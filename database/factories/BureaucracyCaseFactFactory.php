<?php

namespace Database\Factories;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BureaucracyCaseFact>
 */
class BureaucracyCaseFactFactory extends Factory
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
            'key' => 'german_level',
            'value' => 'b1',
            'state' => 'confirmed',
            'source' => 'factory',
            'source_reference' => null,
            'confirmed_at' => now(),
            'reconfirm_at' => now()->addDays(180),
            'superseded_at' => null,
        ];
    }

    public function candidate(): static
    {
        return $this->state(fn (): array => [
            'state' => 'candidate',
            'confirmed_at' => null,
            'reconfirm_at' => null,
        ]);
    }
}
