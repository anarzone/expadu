<?php

namespace App\Composer;

use App\Models\User;
use App\Places\PlaceIdentity;
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
        $plan = app(PlaceIdentity::class)->normalizePlan($plan);

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
            'constraints' => $plan['constraints'] ?? null,
            'origin' => $plan['origin'] ?? null,
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
        // Earlier Today snapshots omitted the constraints needed to revalidate
        // a recommendation. Keep their stored references intact, but require a
        // fresh plan instead of displaying stale free/access/availability claims.
        if (! isset($data['constraints']['window_end'])) {
            return null;
        }
        $data = app(PlaceIdentity::class)->normalizePlan($data);

        if (isset($data['constraints']['window_end'])) {
            $constraints = Constraints::fromArray($data['constraints']);
            [$lat, $lng] = $data['origin'] ?? [50.9375, 6.9603];
            $repository = app(CandidateRepository::class);
            $candidates = collect($repository->byIds(array_column($data['slots'], 'id'), $constraints->windowStart, $lat, $lng))->keyBy('id');
            foreach ($data['slots'] as $index => $slot) {
                if (! str_starts_with($slot['id'], 'spot:')) {
                    continue;
                }
                $candidate = $candidates->get($slot['id']);
                if ($candidate === null || (! app(FeasibilityFilter::class)->matchesDiscovery($constraints, $candidate) || ! $candidate->coversVisit(CarbonImmutable::parse($slot['start_at']), CarbonImmutable::parse($slot['end_at'])))) {
                    return null;
                }
                $data['slots'][$index] = (new PlanSlot(
                    $candidate, CarbonImmutable::parse($slot['start_at']), CarbonImmutable::parse($slot['end_at']),
                    (int) $slot['travel_min_from_previous'], $slot['why'] ?? null,
                ))->toArray();
            }
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

    /** @return array{status: string, weekday: string, prompt: ?string, slots: list<array<string, mixed>>}|null */
    public function getForDisplay(User $user): ?array
    {
        $plan = $this->get($user);
        if ($plan !== null) {
            return ['status' => 'ready', ...$plan];
        }

        $snapshot = Cache::get($this->key($user));
        if (! is_array($snapshot) || empty($snapshot['slots'])) {
            return null;
        }

        return [
            'status' => 'needs_review',
            'weekday' => is_string($snapshot['window_start'] ?? null)
                ? CarbonImmutable::parse($snapshot['window_start'])->isoFormat('dddd')
                : 'day',
            'prompt' => is_string($snapshot['prompt'] ?? null) ? $snapshot['prompt'] : null,
            'slots' => [],
        ];
    }

    public function forget(User $user): void
    {
        Cache::forget($this->key($user));
    }
}
