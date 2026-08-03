<?php

namespace Database\Factories;

use App\Models\BureaucracyCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BureaucracyCase>
 */
class BureaucracyCaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => 'active',
            'fact_version' => 1,
            'ai_consent_at' => null,
            'ai_consent_withdrawn_at' => null,
            'last_assessed_at' => null,
        ];
    }
}
