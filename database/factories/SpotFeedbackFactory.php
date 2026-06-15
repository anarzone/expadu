<?php

namespace Database\Factories;

use App\Enums\SpotFeedbackState;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpotFeedback>
 */
class SpotFeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'spot_id' => Spot::factory(),
            'state' => SpotFeedbackState::Saved,
            'rating' => null,
        ];
    }

    public function been(?string $rating = 'up'): static
    {
        return $this->state(fn () => ['state' => SpotFeedbackState::Been, 'rating' => $rating]);
    }

    public function notInterested(): static
    {
        return $this->state(fn () => ['state' => SpotFeedbackState::NotInterested, 'rating' => null]);
    }
}
