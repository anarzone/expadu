<?php

namespace App\Composer;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Intent signals, not surveillance: aggregates the events the product
 * already generates ("take me there" taps, saves, views, swap-aways)
 * into simple weighted counts per category × Veedel. No new infra.
 *
 * Every signal is written server-side, from the action that earns it
 * (TasteSignals, PlaceFeedbackController, the Composer swap) — never by
 * the client: /api/track only accepts its own allow-list.
 */
class IntentWeights
{
    private const SIGNAL_WEIGHTS = [
        'take_me_there' => 3.0,   // near-certain visit intent
        'event_saved' => 2.0,
        'spot_viewed' => 0.5,
        'composer_swap_away' => -1.5,
        // Place feedback (see PlaceFeedbackController). "More like this" is the
        // forward-looking signal — no visit needed; "been" carries the taste.
        'spot_more_like_this' => 2.5,
        'spot_saved' => 2.0,
        'spot_not_interested' => -2.5,
        'spot_been_liked' => 3.0,
        'spot_been_disliked' => -3.0,
    ];

    /**
     * @return array<string, float> "category|veedel" → weight in [-1, 1]; negative where the person turned it down
     */
    public function for(User $user): array
    {
        // Without the personalisation opt-in, plans rank the same for everyone.
        if (! $user->personalises()) {
            return [];
        }
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

        // Normalise against the strongest signal either way, keeping the sign:
        // a turned-down category should rank below one never seen.
        $max = max([1.0, ...array_map('abs', $raw)]);

        return array_map(
            fn (float $weight) => round($weight / $max, 3),
            $raw,
        );
    }

    /**
     * Anonymous category-only taste vector for grounded planning. No event,
     * place, location, or user identifiers leave this service.
     *
     * @return array{category_intent: array<string, float>, category_dislikes: array<string, float>}
     */
    public function preferenceVector(User $user): array
    {
        if (! $user->personalises()) {
            return ['category_intent' => [], 'category_dislikes' => []];
        }
        $totals = [];
        $rows = DB::table('user_events')
            ->where('user_id', $user->id)
            ->whereIn('event_type', array_keys(self::SIGNAL_WEIGHTS))
            ->where('created_at', '>=', now()->subDays(90))
            ->get(['event_type', 'payload']);

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $category = $payload['category'] ?? null;
            if (! is_string($category) || $category === '') {
                continue;
            }
            $totals[$category] = ($totals[$category] ?? 0.0) + self::SIGNAL_WEIGHTS[$row->event_type];
        }

        $scale = max([1.0, ...array_map('abs', $totals)]);
        $intent = [];
        $dislikes = [];
        foreach ($totals as $category => $total) {
            if ($total >= 0) {
                $intent[$category] = round($total / $scale, 3);
            } else {
                $dislikes[$category] = round(abs($total) / $scale, 3);
            }
        }

        return ['category_intent' => $intent, 'category_dislikes' => $dislikes];
    }
}
