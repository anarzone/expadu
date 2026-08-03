<?php

namespace Database\Factories;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BureaucracyFactConflict>
 */
class BureaucracyFactConflictFactory extends Factory
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
            'fact_key' => 'citizenship_group',
            'existing_fact_id' => fn (array $attributes): int => BureaucracyCaseFact::factory()->create([
                'case_id' => $attributes['case_id'],
                'key' => 'citizenship_group',
                'value' => 'non_eu',
            ])->id,
            'candidate_fact_id' => fn (array $attributes): int => BureaucracyCaseFact::factory()->candidate()->create([
                'case_id' => $attributes['case_id'],
                'key' => 'citizenship_group',
                'value' => 'eu',
            ])->id,
            'status' => 'unresolved',
            'resolved_fact_id' => null,
            'resolved_at' => null,
        ];
    }
}
