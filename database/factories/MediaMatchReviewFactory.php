<?php

namespace Database\Factories;

use App\Models\MediaAttachment;
use App\Models\MediaMatchReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaMatchReview>
 */
class MediaMatchReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_attachment_id' => MediaAttachment::factory(),
            'previous_status' => 'pending',
            'new_status' => 'accepted',
            'match_method' => 'manual_review',
            'evidence' => fake()->sentence(12),
            'reviewer' => fake()->safeEmail(),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'snapshot' => ['review' => 'factory'],
            'created_at' => now(),
        ];
    }
}
