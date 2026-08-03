<?php

namespace Database\Factories;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyPlanSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BureaucracyPlanSnapshot>
 */
class BureaucracyPlanSnapshotFactory extends Factory
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
            'fact_version' => 1,
            'rules_hash' => hash('sha256', fake()->uuid()),
            'rule_versions' => [],
            'coverage_state' => 'needs_information',
            'sections' => [],
            'unresolved_facts' => [],
            'reassessment_at' => null,
            'generated_at' => now(),
            'superseded_at' => null,
        ];
    }
}
