<?php

namespace App\Composer;

use App\Models\ComposerWorkspace;
use App\Models\User;
use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Reads today's confirmed workspace plan, with compatibility for the older
 * cached Today pin. Dismissing this summary never cancels the underlying day.
 */
class TodayPlanStore
{
    private const MAX_TTL_HOURS = 72;

    public function __construct(private PrivatePlanCache $cache, private ActivePlanStore $active) {}

    /** Marks a dismissed workspace day; holds a hash only, never plan contents. */
    private function hiddenKey(User $user): string
    {
        return "composer:today:{$user->id}:hidden";
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
        $this->active->assertCurrent($user, $plan);

        app(WorkspaceStore::class)->saveConfirmed($user, $plan + ['prompt' => $prompt]);

        Cache::forget($this->hiddenKey($user));
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
     * @return array{date: ?string, weekday: string, prompt: ?string, slots: list<array<string, mixed>>}|null
     */
    public function get(User $user): ?array
    {
        $workspace = ComposerWorkspace::query()->where('user_id', $user->id)->first();
        $data = $this->cache->get($user, 'today');
        $today = CarbonImmutable::now('Europe/Berlin')->format('Y-m-d');
        $pinnedDate = is_array($data) && isset($data['window_start'])
            ? CarbonImmutable::parse($data['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d') : null;
        if ($workspace !== null) {
            $date = isset($workspace->state['plans'][$today]) ? $today : ($pinnedDate ?? $today);
            $plan = $workspace->state['plans'][$date] ?? null;
            if ($plan && ! empty($plan['slots'])) {
                if (Cache::get($this->hiddenKey($user)) === hash('sha256', json_encode($plan))) {
                    return null;
                }

                $weekday = CarbonImmutable::parse($date)->isoFormat('dddd');
                if (! $this->appointmentsCurrent($user, $plan)) {
                    return ['state' => 'needs_review', 'date' => $date, 'weekday' => $weekday, 'prompt' => null, 'slots' => [],
                        'message' => 'Your appointments changed. Rebuild your day plan to use the current times.', 'action' => 'recompose'];
                }
                $plan = app(PlanCatalogue::class)->project($plan);

                return ['state' => 'current', 'date' => $date, 'weekday' => $weekday,
                    'schedule_feasible' => $plan['schedule_feasible'] ?? true, 'notices' => $this->notices($plan),
                    'prompt' => $plan['prompt'] ?? null, 'slots' => $plan['slots']];
            }
            // A day that had a plan and was cancelled stays cancelled; the older cached pin doesn't return.
            if (collect(app(WorkspaceUpgrade::class)->toCurrent($workspace->state)['changes'])->contains('date', $date)) {
                return null;
            }
        }
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
            return ['state' => 'needs_review', 'date' => $pinnedDate, 'weekday' => $weekday, 'prompt' => null, 'slots' => [],
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
                $fresh = (new PlanSlot(
                    $candidate, CarbonImmutable::parse($slot['start_at']), CarbonImmutable::parse($slot['end_at']),
                    (int) $slot['travel_min_from_previous'], $slot['why'] ?? null,
                ))->toArray();
                // Keep the plan-level leg flags: no journey next to an unroutable appointment.
                $fresh = [...$fresh, 'travel_known' => $slot['travel_known'] ?? true, 'may_overlap_previous' => $slot['may_overlap_previous'] ?? false];
                $data['slots'][$index] = $fresh['travel_known'] ? $fresh : [...$fresh, 'travel_min_from_previous' => null, 'leave_by' => null];
            }
        }
        $data = app(PlanCatalogue::class)->project($data);

        return [
            'state' => 'current',
            'date' => $pinnedDate,
            'schedule_feasible' => $data['schedule_feasible'] ?? true,
            'notices' => $this->notices($data),
            'weekday' => $weekday,
            'prompt' => is_string($data['prompt'] ?? null) ? $data['prompt'] : null,
            'slots' => array_values($data['slots']),
        ];
    }

    /** @return list<array{code: string, text: string}> */
    private function notices(array $plan): array
    {
        return [...(($plan['schedule_feasible'] ?? true) ? [] : [[
            'code' => 'appointment_conflict', 'text' => 'Your recorded appointments overlap or cannot all be reached in time. Review the timings before following this plan.',
        ]]), ...Plan::appointmentNotices($plan['slots'])];
    }

    /**
     * A saved day that carries recorded appointments is only shown while those
     * records still match; a day built without them must not hold one either.
     */
    private function appointmentsCurrent(User $user, array $plan): bool
    {
        $hasAppointment = collect($plan['slots'])->contains(fn (array $slot): bool => ($slot['is_appointment'] ?? false) === true || ($slot['type'] ?? null) === 'appointment');
        if (! isset($plan['appointment_revision'])) {
            return ! $hasAppointment;
        }
        try {
            $this->active->assertCurrent($user, $plan);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /** Dismiss the Today summary without cancelling a saved day. */
    public function forget(User $user): void
    {
        $state = ComposerWorkspace::query()->where('user_id', $user->id)->first()?->state ?? ['plans' => []];
        $today = CarbonImmutable::now('Europe/Berlin')->format('Y-m-d');
        $cached = $this->cache->get($user, 'today');
        $date = isset($state['plans'][$today]) ? $today : (isset($cached['window_start']) ? CarbonImmutable::parse($cached['window_start'])->setTimezone('Europe/Berlin')->format('Y-m-d') : null);
        if ($date && isset($state['plans'][$date])) {
            Cache::put($this->hiddenKey($user), hash('sha256', json_encode($state['plans'][$date])), now()->addDays(4));
        }
        $this->cache->forget($user, 'today');
    }
}
