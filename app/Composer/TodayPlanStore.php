<?php

namespace App\Composer;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The one plan a user has pinned to their Today screen. "Save to Today" in the
 * composer copies the live plan (already in composer:plan:{user}) into this
 * separate key so it survives navigation and shows on the home screen; the
 * Today screen reads it back. Lives in Redis until the end of the plan's day
 * (a "today" plan shouldn't linger), capped at the composer's 72h horizon.
 */
class TodayPlanStore
{
    private const MAX_TTL_HOURS = 72;

    private function key(User $user): string
    {
        return "composer:today:{$user->id}";
    }

    /**
     * Pin a composed plan (its stored array form) to Today.
     *
     * @param  array<string, mixed>  $plan  the cached composer:plan payload
     */
    public function save(User $user, array $plan, ?string $prompt): void
    {
        if (empty($plan['slots'])) {
            return;
        }

        $start = $plan['constraints']['window_start'] ?? null;
        $until = is_string($start)
            ? CarbonImmutable::parse($start)->endOfDay()->addHours(6)
            : CarbonImmutable::now()->addDay();

        $ttl = max(
            3600,
            min(self::MAX_TTL_HOURS * 3600, (int) CarbonImmutable::now()->diffInSeconds($until, false)),
        );

        Cache::put($this->key($user), [
            'window_start' => $start,
            'prompt' => $prompt,
            'slots' => array_values($plan['slots']),
            'saved_at' => CarbonImmutable::now()->toIso8601String(),
        ], $ttl);
    }

    /**
     * The Today screen's view of the pinned plan, or null when none is set.
     *
     * @return array{weekday: string, prompt: ?string, slots: list<array<string, mixed>>}|null
     */
    public function get(User $user): ?array
    {
        $data = Cache::get($this->key($user));
        if (! is_array($data) || empty($data['slots'])) {
            return null;
        }

        $weekday = isset($data['window_start']) && is_string($data['window_start'])
            ? CarbonImmutable::parse($data['window_start'])->isoFormat('dddd')
            : 'day';

        return [
            'weekday' => $weekday,
            'prompt' => is_string($data['prompt'] ?? null) ? $data['prompt'] : null,
            'slots' => array_values($data['slots']),
        ];
    }

    public function forget(User $user): void
    {
        Cache::forget($this->key($user));
    }
}
