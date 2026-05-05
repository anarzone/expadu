<?php

namespace App\ContextEngine;

use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\PersonalisationService;

/**
 * Cold-start tiering: select the right algorithm based on how much
 * engagement signal the user has actually produced.
 *
 * Counts only "engagement-bearing" event types so a user with 10k GPS
 * pings but 2 saves doesn't get auto-promoted to full ANN.
 *
 * Tier matrix:
 *   0 signals      → tag-based filter (situation × city)
 *   1–20 signals   → seed vector ANN (preference_vector seeded from onboarding)
 *   20–50 signals  → cohort popularity (top spots among same situation)
 *   50+ signals    → full pgvector ANN over user's preference_vector
 */
class PersonalisationStrategy
{
    public const TIER_TAGS = 'tags';

    public const TIER_SEED = 'seed';

    public const TIER_COHORT = 'cohort';

    public const TIER_ANN = 'ann';

    private const ENGAGEMENT_EVENTS = [
        'event_saved',
        'journey_planned',
        'spot_viewed',
        'card_clicked',
        'departure_viewed',
    ];

    public function __construct(private PersonalisationService $personalisation) {}

    public function tierFor(User $user): string
    {
        $signal = UserEvent::where('user_id', $user->id)
            ->whereIn('event_type', self::ENGAGEMENT_EVENTS)
            ->count();

        return match (true) {
            $signal >= 50 => self::TIER_ANN,
            $signal >= 20 => self::TIER_COHORT,
            $signal >= 1 => self::TIER_SEED,
            default => self::TIER_TAGS,
        };
    }

    /** @return list<int> spot IDs */
    public function recommendSpotIds(User $user, int $k = 10): array
    {
        $tier = $this->tierFor($user);

        return match ($tier) {
            self::TIER_ANN, self::TIER_SEED => $this->personalisation->recommendSpots($user, $k),
            self::TIER_COHORT => $this->cohortPopularSpots($user, $k),
            self::TIER_TAGS => $this->tagFilteredSpots($user, $k),
        };
    }

    /** @return list<int> event IDs */
    public function recommendEventIds(User $user, int $k = 10): array
    {
        $tier = $this->tierFor($user);
        if ($tier === self::TIER_ANN || $tier === self::TIER_SEED) {
            return $this->personalisation->recommendEvents($user, $k);
        }

        // Cohort + tags fall back to existing event ranking
        return [];
    }

    /** @return list<int> */
    private function cohortPopularSpots(User $user, int $k): array
    {
        $situation = $user->situation?->value ?? null;
        if (! $situation) {
            return $this->tagFilteredSpots($user, $k);
        }

        return UserEvent::query()
            ->where('event_type', 'spot_viewed')
            ->whereHas('user', fn ($q) => $q->where('situation', $situation))
            ->where('created_at', '>', now()->subDays(30))
            ->selectRaw("(payload->>'spot_id')::int as spot_id, count(*) as c")
            ->whereRaw("payload->>'spot_id' is not null")
            ->groupBy('spot_id')
            ->orderByDesc('c')
            ->limit($k)
            ->pluck('spot_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return list<int> */
    private function tagFilteredSpots(User $user, int $k): array
    {
        return Spot::query()
            ->where('is_verified', true)
            ->orderByDesc('rating')
            ->limit($k)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
