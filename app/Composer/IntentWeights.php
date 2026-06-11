<?php

namespace App\Composer;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Intent signals, not surveillance: aggregates the events the product
 * already generates ("take me there" taps, saves, views, swap-aways)
 * into simple weighted counts per category × Veedel. No new infra.
 */
class IntentWeights
{
    private const SIGNAL_WEIGHTS = [
        'take_me_there' => 3.0,   // near-certain visit intent
        'event_saved' => 2.0,
        'spot_viewed' => 0.5,
        'composer_swap_away' => -1.5,
        'post_trip_thumbs_up' => 3.0,
        'post_trip_thumbs_down' => -3.0,
    ];

    /**
     * @return array<string, float> "category|veedel" → weight in [0, 1]
     */
    public function for(User $user): array
    {
        $rows = DB::table('user_events')
            ->where('user_id', $user->id)
            ->whereIn('event_type', array_keys(self::SIGNAL_WEIGHTS))
            ->where('created_at', '>=', now()->subDays(90))
            ->get(['event_type', 'payload']);

        $raw = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $category = $payload['category'] ?? null;
            $veedel = $payload['veedel'] ?? null;
            if (! $category) {
                continue;
            }

            $key = "{$category}|{$veedel}";
            $raw[$key] = ($raw[$key] ?? 0.0) + self::SIGNAL_WEIGHTS[$row->event_type];
        }

        if ($raw === []) {
            return [];
        }

        // Normalise to [0, 1] against the strongest signal.
        $max = max(max($raw), 1.0);

        return array_map(
            fn (float $weight) => max(0.0, round($weight / $max, 3)),
            $raw,
        );
    }
}
