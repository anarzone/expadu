<?php

namespace App\Http\Controllers;

use App\Composer\AppointmentRepository;
use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\Contracts\EstimatesTravel;
use App\Composer\Contracts\ParsesPrompt;
use App\Composer\FeasibilityFilter;
use App\Composer\IntentWeights;
use App\Composer\MatrixTravelEstimator;
use App\Composer\Plan;
use App\Composer\PlanNarrator;
use App\Composer\PlanScorer;
use App\Composer\PlanSlot;
use App\Composer\ScoringContext;
use App\Composer\SlotFiller;
use App\Composer\Swapper;
use App\Composer\TravelEstimator;
use App\Enums\SpotCategory;
use App\Models\User;
use App\Models\UserEvent;
use App\Profile\CategoryAffinity;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use App\Services\GermanHolidayService;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use App\Transit\Dto\GeoPoint;
use App\Transit\TravelTimes;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Day Composer endpoints: parse (the only LLM call), compose
 * (deterministic pipeline), swap (single-slot re-score). Plan state
 * lives in Redis for 72h — the composer's whole scope.
 */
class ComposerController extends Controller
{
    private const PLAN_TTL_HOURS = 72;

    private ?array $forecastCache = null;

    /**
     * The single parse call: classify intent + extract payload. The result
     * carries the intent so the frontend renders the right surface (plan,
     * verified answer, or search), plus `source` so a degraded parse is
     * framed honestly. The parser never picks venues or answers questions.
     */
    public function parse(Request $request, ParsesPrompt $parser, ProfileEngine $profiles): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        $parsed = $parser->parse(
            $validated['text'],
            $profiles->build($request->user()),
            CarbonImmutable::now('Europe/Berlin'),
        );

        return response()->json($parsed->toArray());
    }

    public function compose(
        Request $request,
        CandidateRepository $candidates,
        AppointmentRepository $appointments,
        FeasibilityFilter $filter,
        ProfileEngine $profiles,
        IntentWeights $intents,
    ): JsonResponse {
        $validated = $request->validate([
            'constraints' => ['required', 'array'],
            'constraints.window_start' => ['required', 'date'],
            'constraints.window_end' => ['required', 'date', 'after:constraints.window_start'],
            'constraints.areas' => ['array'],
            'constraints.categories' => ['array'],
            'constraints.companions' => ['nullable', 'string'],
            'constraints.budget' => ['nullable', 'string'],
            'constraints.archetype' => ['nullable', 'string'],
            'constraints.vibe' => ['nullable', 'string'],
            'pins' => ['array'],
            'pins.*' => ['string'],
            'locked' => ['array'],
            'locked.*' => ['string'],
            'excluded' => ['array'],
            'excluded.*' => ['string'],
        ]);

        $user = $request->user();
        $constraints = Constraints::fromArray($validated['constraints']);

        // Hard scope guard, independent of the parser
        if ($constraints->windowMinutes() > 72 * 60) {
            return response()->json(['message' => 'The composer plans at most 72 hours ahead.'], 422);
        }

        $profile = $profiles->build($user);

        // "Locked" picks from the result page are kept across recomposes —
        // mechanically identical to home-feed pins, so they merge.
        $pins = array_values(array_unique([...($validated['pins'] ?? []), ...($validated['locked'] ?? [])]));
        $excluded = array_values($validated['excluded'] ?? []);

        $context = new ScoringContext(
            rainExpected: $this->rainExpected(),
            preferredAreas: $constraints->areas !== [] ? $constraints->areas : $profile->defaultAreas,
            intentWeights: $intents->for($user),
            companions: $constraints->companions,
            pinnedIds: $pins,
            affinity: CategoryAffinity::map($profile),
        );

        // The plan's start: the shared origin (live / confirmed / recent ping),
        // else the area being planned, else Cologne centre. The user's home is
        // no longer a hidden anchor.
        $originContext = app(UserLocationService::class)->context($user, $request, $constraints->areas[0] ?? null);
        [$originLat, $originLng] = $originContext->hasOrigin()
            ? [$originContext->lat, $originContext->lng]
            : [50.9375, 6.9603];

        // Leisure runs the feasibility gauntlet; appointments and pinned/locked
        // picks bypass it (the user chose them). "Excluded" picks (removed, or
        // dropped by Shuffle) are filtered out of the whole pool.
        $rawPool = $candidates->candidatesFor($constraints);
        $feasible = $filter->filter($constraints, $rawPool);

        // Never dead-end on an over-tight filter combination: if nothing is
        // feasible, lift the category filter (keeping a "free" promise as long
        // as possible), then the budget cap, until the pool has something. The
        // plan then carries an honest "widened your picks" notice.
        $poolRelaxed = false;
        if ($feasible === []) {
            foreach ([$constraints->withoutCategories(), $constraints->withoutBudget(), $constraints->withoutBudget()->withoutCategories()] as $relaxed) {
                $feasible = $filter->filter($relaxed, $rawPool);
                if ($feasible !== []) {
                    $poolRelaxed = true;
                    break;
                }
            }
        }

        $pinned = $candidates->byIds($pins, $constraints->windowStart);
        $pool = collect([...$appointments->within($user, $constraints), ...$pinned, ...$feasible])
            ->reject(fn (Candidate $c) => in_array($c->id, $excluded, true))
            ->unique(fn (Candidate $c) => $c->id)
            ->values()
            ->all();

        $estimator = $this->travelEstimator($user, $originLat, $originLng, $pool);
        $filler = new SlotFiller(new PlanScorer($estimator), $estimator);
        $plan = $filler->fill($constraints, $pool, $context, $originLat, $originLng);

        Cache::put($this->planKey($user), $plan->toArray() + [
            'origin' => [$originLat, $originLng],
            'pins' => $pins,
            'excluded' => $excluded,
        ], now()->addHours(self::PLAN_TTL_HOURS));

        return response()->json([
            'plan' => $plan->toArray(),
            'notices' => $this->notices($constraints, $plan, $poolRelaxed),
            'facets' => $this->facets($rawPool, $profile),
            'origin' => [
                'source' => $originContext->source->value,
                'label' => $originContext->label,
            ],
        ]);
    }

    /**
     * Adaptive options for the editable filter chips: the coarse categories
     * that actually have candidates, and real neighbourhoods to choose from
     * (the user's home areas first, then the busiest others). So a dropdown
     * offers real choices, not all 25 Veedels or categories with nothing in
     * them.
     *
     * @param  list<Candidate>  $pool
     * @return array{categories: list<array{value: string, label: string}>, areas: list<string>}
     */
    private function facets(array $pool, Profile $profile): array
    {
        $buckets = [];
        $areas = [];
        foreach ($pool as $candidate) {
            $coarse = SpotCategory::tryFrom($candidate->category)?->coarse();
            if ($coarse !== null && $coarse !== 'other') {
                $buckets[$coarse] = true;
            }
            if ($candidate->veedel !== null && $candidate->veedel !== '') {
                $areas[$candidate->veedel] = ($areas[$candidate->veedel] ?? 0) + 1;
            }
        }

        ksort($buckets);
        $categories = array_map(
            fn (string $value) => ['value' => $value, 'label' => SpotCategory::coarseLabel($value)],
            array_keys($buckets),
        );

        arsort($areas);
        $areaList = collect($profile->defaultAreas)
            ->merge(array_keys($areas))
            ->unique()
            ->values()
            ->take(14)
            ->all();

        return ['categories' => $categories, 'areas' => $areaList];
    }

    public function swap(
        Request $request,
        CandidateRepository $candidates,
        AppointmentRepository $appointments,
        FeasibilityFilter $filter,
        ProfileEngine $profiles,
        IntentWeights $intents,
    ): JsonResponse {
        $validated = $request->validate([
            'slot' => ['required', 'integer', 'min:0', 'max:10'],
        ]);

        $user = $request->user();
        $stored = Cache::get($this->planKey($user));
        if (! is_array($stored)) {
            return response()->json(['message' => 'No active plan — compose one first.'], 404);
        }

        $pins = $stored['pins'] ?? [];
        $excluded = $stored['excluded'] ?? [];
        $storedConstraints = Constraints::fromArray($stored['constraints']);
        $appointmentPool = $appointments->within($user, $storedConstraints);
        $plan = $this->hydratePlan($stored, $candidates, $appointmentPool, $candidates->byIds($pins, $storedConstraints->windowStart));
        $slotIndex = (int) $validated['slot'];
        $rejected = $stored['rejected'][$slotIndex] ?? [];

        // Appointments and fixed-time events are anchors — refuse before
        // recording a (spurious) negative signal for the scorer.
        $target = $plan->slots[$slotIndex] ?? null;
        if ($target !== null && ! $target->candidate->swappable) {
            return response()->json(['message' => 'This slot is fixed and cannot be swapped.'], 422);
        }

        if (isset($plan->slots[$slotIndex])) {
            $outgoing = $plan->slots[$slotIndex]->candidate;
            $rejected[] = $outgoing->id;

            // Swap-away is a negative intent signal for the scorer.
            UserEvent::create([
                'user_id' => $user->id,
                'event_type' => 'composer_swap_away',
                'payload' => [
                    'candidate_id' => $outgoing->id,
                    'category' => $outgoing->category,
                    'veedel' => $outgoing->veedel,
                ],
            ]);
        }

        $profile = $profiles->build($user);
        $context = new ScoringContext(
            rainExpected: $this->rainExpected(),
            preferredAreas: $plan->constraints->areas !== [] ? $plan->constraints->areas : $profile->defaultAreas,
            intentWeights: $intents->for($user),
            companions: $plan->constraints->companions,
            pinnedIds: $pins,
            affinity: CategoryAffinity::map($profile),
        );

        [$originLat, $originLng] = $stored['origin'] ?? $this->origin($user);

        // Honour removed picks here too — a removed spot must not return via Swap.
        $feasible = collect($filter->filter($plan->constraints, $candidates->candidatesFor($plan->constraints)))
            ->reject(fn (Candidate $c) => in_array($c->id, $excluded, true))
            ->values()
            ->all();
        $estimator = $this->travelEstimator($user, $originLat, $originLng, $feasible);
        $swapper = new Swapper(new PlanScorer($estimator), $estimator);
        $swapped = $swapper->swap($plan, $slotIndex, $feasible, $context, $originLat, $originLng, $rejected);

        if ($swapped === null) {
            return response()->json(['message' => 'No alternative fits this slot.'], 422);
        }

        // Re-narrate so the swapped slot (and the one after it) keep their "why".
        $swapped = new Plan($swapped->constraints, PlanNarrator::narrate($swapped->slots, $context->rainExpected));

        $rejectedMap = $stored['rejected'] ?? [];
        $rejectedMap[$slotIndex] = $rejected;

        Cache::put($this->planKey($user), $swapped->toArray() + [
            'origin' => [$originLat, $originLng],
            'rejected' => $rejectedMap,
            'pins' => $pins,
            'excluded' => $excluded,
        ], now()->addHours(self::PLAN_TTL_HOURS));

        return response()->json([
            'plan' => $swapped->toArray(),
            'notices' => $this->notices($swapped->constraints, $swapped),
        ]);
    }

    private function planKey(User $user): string
    {
        return "composer:plan:{$user->id}";
    }

    /**
     * Weather forecast for the composer, fetched at most once per request.
     *
     * @return array<string, mixed>
     */
    private function forecast(): array
    {
        if ($this->forecastCache !== null) {
            return $this->forecastCache;
        }

        try {
            return $this->forecastCache = app(WeatherService::class)->getForecast();
        } catch (\Throwable) {
            return $this->forecastCache = [];
        }
    }

    private function rainExpected(): bool
    {
        // `rain_soon`: rain within the near-term window the weather widget
        // summarises — not `rain_starts`, which names any later hour of the day
        // and so stamped "indoor — beats the rain" on every slot of a dry day.
        return (bool) ($this->forecast()['rain_soon'] ?? false);
    }

    /**
     * Deterministic, honest plan annotations: the woven appointment, the
     * weather call the scorer already acted on, and the German rhythm of
     * the planned day (Sunday/holiday closures, a holiday-eve grocery
     * nudge). Notices explain the plan; they never invent venue facts.
     *
     * @return list<array{type: string, text: string}>
     */
    private function notices(Constraints $constraints, Plan $plan, bool $poolRelaxed = false): array
    {
        $notices = [];

        // The plan was widened beyond the exact filters (a shaped day with no
        // matching picks, or an over-tight combination) — say so honestly.
        if ($poolRelaxed || $plan->relaxed) {
            $notices[] = ['type' => 'info', 'text' => '✨ Few exact matches here — widened the picks so you still have a plan'];
        }

        foreach ($plan->slots as $slot) {
            if ($slot->candidate->isAppointment()) {
                $notices[] = ['type' => 'info', 'text' => '🏛️ Built around your '.$slot->startAt->format('H:i').' appointment'];
                break;
            }
        }

        if ($this->rainExpected()) {
            $summary = $this->forecast()['rain_summary'] ?? null;
            $notices[] = ['type' => 'warn', 'text' => '🌧 '.(is_string($summary) && $summary !== '' ? $summary : 'Rain expected — indoor picks favoured')];
        }

        // German shops shut on Sundays and public holidays — NOT Saturdays.
        $holidays = app(GermanHolidayService::class);
        $day = $constraints->windowStart;
        $tomorrow = $day->addDay();

        if ($day->isSunday() || $holidays->isHoliday($day)) {
            $label = $holidays->getHolidayName($day) ?? 'Sunday';
            $notices[] = ['type' => 'warn', 'text' => "{$label} — most shops shut; parks, museums and cafés are your best bets"];
        } elseif ($tomorrow->isSunday() || $holidays->isHoliday($tomorrow)) {
            $label = $holidays->getHolidayName($tomorrow) ?? 'Sunday';
            $notices[] = ['type' => 'warn', 'text' => "🛒 {$label} tomorrow — shops shut. Grab groceries today"];
        }

        return $notices;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function origin(User $user): array
    {
        $context = app(UserLocationService::class)->context($user);

        return $context->hasOrigin()
            ? [$context->lat, $context->lng]
            : [50.9375, 6.9603]; // Cologne centre — a plan must start somewhere
    }

    /**
     * Real one-to-many street minutes from the origin to the candidate pool, in
     * the user's mode, wrapped so the pipeline uses them for the origin leg and
     * the haversine heuristic for everything else. Falls back to pure haversine
     * when MOTIS is unavailable, so a plan always composes.
     *
     * @param  list<Candidate>  $pool
     */
    private function travelEstimator(User $user, float $originLat, float $originLng, array $pool): EstimatesTravel
    {
        $fallback = new TravelEstimator;
        if ($pool === []) {
            return $fallback;
        }

        $candidates = array_values($pool);
        $destinations = array_map(
            fn (Candidate $c) => new GeoPoint((float) $c->lat, (float) $c->lng),
            $candidates,
        );

        try {
            $minutes = app(TravelTimes::class)->minutes(
                $user->transport_mode,
                new GeoPoint($originLat, $originLng),
                $destinations,
            );
        } catch (\Throwable) {
            return $fallback;
        }

        $map = [];
        foreach ($candidates as $i => $candidate) {
            $real = $minutes[$i] ?? null;
            if ($real !== null) {
                $map[MatrixTravelEstimator::coordKey((float) $candidate->lat, (float) $candidate->lng)] = $real;
            }
        }

        return $map === []
            ? $fallback
            : new MatrixTravelEstimator($fallback, $originLat, $originLng, $map);
    }

    /**
     * Rebuild a Plan from its stored array using fresh candidate snapshots
     * (so swapped-in venues carry current data). Appointment anchors aren't
     * in the leisure pool, so they're merged back in by id — otherwise a
     * swap would silently drop the user's appointment from the plan.
     *
     * @param  array<string, mixed>  $stored
     * @param  list<Candidate>  $appointmentPool
     * @param  list<Candidate>  $pinnedPool
     */
    private function hydratePlan(array $stored, CandidateRepository $candidates, array $appointmentPool = [], array $pinnedPool = []): Plan
    {
        $constraints = Constraints::fromArray($stored['constraints']);
        $pool = collect([...$candidates->candidatesFor($constraints), ...$appointmentPool, ...$pinnedPool])->keyBy('id');

        $slots = [];
        foreach ($stored['slots'] as $slotData) {
            $candidate = $pool->get($slotData['id']);
            if ($candidate === null) {
                continue; // venue vanished between compose and swap
            }
            $slots[] = new PlanSlot(
                candidate: $candidate,
                startAt: CarbonImmutable::parse($slotData['start_at']),
                endAt: CarbonImmutable::parse($slotData['end_at']),
                travelMinFromPrevious: (int) $slotData['travel_min_from_previous'],
            );
        }

        return new Plan($constraints, $slots);
    }
}
