<?php

namespace Database\Factories;

use App\Models\LanguagePartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LanguagePartner> */
class LanguagePartnerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'requester_id' => User::factory(),
            'receiver_id' => User::factory(),
            'status' => 'pending',
            'matched_at' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'matched_at' => now(),
        ]);
    }
}
