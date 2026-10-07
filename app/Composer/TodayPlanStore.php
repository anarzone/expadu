<?php

namespace App\Composer;

use App\Models\User;
use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

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

    public function __construct(private PrivatePlanCache $cache, private ActivePlanStore $active) {}

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
        $this->active->assertCurrent($user, $plan);

        $start = $plan['constraints']['window_start'] ?? null;
        $until = is_string($start)
            ? CarbonImmutable::parse($start)->endOfDay()->addHours(6)
            : CarbonImmutable::now()->addDay();

        $ttl = max(
            3600,
            min(self::MAX_TTL_HOURS * 3600, (int) CarbonImmutable::now()->diffInSeconds($until, false)),
        );

        $this->active->store($user, 'today', [
            'constraints' => $plan['constraints'],
            'appointment_revision' => $plan['appointment_revision'],
            'schedule_feasible' => $plan['schedule_feasible'] ?? true,
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
        $data = $this->cache->get($user, 'today');
        if (! is_array($data) || empty($data['slots'])) {
            return null;
        }
        // Earlier Today snapshots omitted the constraints needed to revalidate
        // a recommendation. Keep their stored references intact, but require a
        // fresh plan instead of displaying stale free/access/availability claims.
        if (! isset($data['constraints']['window_end'])) {
            return null;
        }
        $weekday = isset($data['window_start']) && is_string($data['window_start'])
            ? CarbonImmutable::parse($data['window_start'])->isoFormat('dddd')
            : 'day';

        try {
            $this->active->assertCurrent($user, $data);
        } catch (ValidationException) {
            return ['state' => 'needs_review', 'weekday' => $weekday, 'prompt' => null, 'slots' => [],
                'message' => 'Your appointments changed. Rebuild your day plan to use the current times.', 'action' => 'recompose'];
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

        return [
            'state' => 'current',
            'schedule_feasible' => $data['schedule_feasible'] ?? true,
            'notices' => ($data['schedule_feasible'] ?? true) ? [] : [[
                'code' => 'appointment_conflict', 'text' => 'Your recorded appointments overlap or cannot all be reached in time. Review the timings before following this plan.',
            ]],
            'weekday' => $weekday,
            'prompt' => is_string($data['prompt'] ?? null) ? $data['prompt'] : null,
            'slots' => array_values($data['slots']),
        ];
    }

    public function forget(User $user): void
    {
        $this->cache->forget($user, 'today');
    }
}
