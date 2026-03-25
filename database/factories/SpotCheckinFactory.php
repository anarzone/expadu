<?php

namespace Database\Factories;

use App\Models\Spot;
use App\Models\SpotCheckin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpotCheckin> */
class SpotCheckinFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spot_id' => Spot::factory(),
            'user_id' => User::factory(),
            'checked_in_at' => now(),
            'checked_out_at' => null,
        ];
    }
}
