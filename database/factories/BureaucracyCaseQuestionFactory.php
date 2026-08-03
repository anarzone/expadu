<?php

namespace Database\Factories;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BureaucracyCaseQuestion>
 */
class BureaucracyCaseQuestionFactory extends Factory
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
            'fact_key' => 'current_residence_title',
            'attempt' => 1,
            'asked_at' => now(),
            'answered_at' => null,
            'outcome' => null,
        ];
    }
}
