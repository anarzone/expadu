<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAttachment>
 */
class MediaAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_asset_id' => MediaAsset::factory(),
            'mediable_type' => Event::class,
            'mediable_id' => Event::factory(),
            'role' => 'hero',
            'priority' => 100,
            'is_primary' => false,
            'is_manually_locked' => false,
            'match_status' => 'pending',
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'match_status' => 'accepted',
            'match_method' => 'manual_review',
            'match_evidence' => ['review' => 'Correct place'],
            'match_reviewed_at' => now(),
        ]);
    }
}
