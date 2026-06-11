<?php

namespace App\Http\Controllers;

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\Contracts\ParsesConstraints;
use App\Composer\FeasibilityFilter;
use App\Composer\IntentWeights;
use App\Composer\Plan;
use App\Composer\PlanSlot;
use App\Composer\ScoringContext;
use App\Composer\SlotFiller;
use App\Composer\Swapper;
use App\Models\User;
use App\Models\UserEvent;
use App\Profile\ProfileEngine;
use App\Services\WeatherService;
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

    public function parse(Request $request, ParsesConstraints $parser, ProfileEngine $profiles): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        $constraints = $parser->parse(
            $validated['text'],
            $profiles->build($request->user()),
            CarbonImmutable::now('Europe/Berlin'),
        );

        return response()->json(['constraints' => $constraints->toArray()]);
    }

    public function compose(
        Request $request,
        CandidateRepository $candidates,
        FeasibilityFilter $filter,
        SlotFiller $filler,
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
        ]);

        $user = $request->user();
        $constraints = Constraints::fromArray($validated['constraints']);

        // Hard scope guard, independent of the parser
        if ($constraints->windowMinutes() > 72 * 60) {
            return response()->json(['message' => 'The composer plans at most 72 hours ahead.'], 422);
        }

        $profile = $profiles->build($user);
        $context = new ScoringContext(
            rainExpected: $this->rainExpected(),
            preferredAreas: $constraints->areas !== [] ? $constraints->areas : $profile->defaultAreas,
            intentWeights: $intents->for($user),
        );

        [$originLat, $originLng] = $this->origin($user);

        $feasible = $filter->filter($constraints, $candidates->candidatesFor($constraints));
        $plan = $filler->fill($constraints, $feasible, $context, $originLat, $originLng);

        Cache::put($this->planKey($user), $plan->toArray() + [
            'origin' => [$originLat, $originLng],
        ], now()->addHours(self::PLAN_TTL_HOURS));

        return response()->json(['plan' => $plan->toArray()]);
    }

    public function swap(
        Request $request,
        CandidateRepository $candidates,
        FeasibilityFilter $filter,
        Swapper $swapper,
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

        $plan = $this->hydratePlan($stored, $candidates);
        $slotIndex = (int) $validated['slot'];
        $rejected = $stored['rejected'][$slotIndex] ?? [];

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
        );

        [$originLat, $originLng] = $stored['origin'] ?? $this->origin($user);

        $feasible = $filter->filter($plan->constraints, $candidates->candidatesFor($plan->constraints));
        $swapped = $swapper->swap($plan, $slotIndex, $feasible, $context, $originLat, $originLng, $rejected);

        if ($swapped === null) {
            return response()->json(['message' => 'No alternative fits this slot.'], 422);
        }

        $rejectedMap = $stored['rejected'] ?? [];
        $rejectedMap[$slotIndex] = $rejected;

        Cache::put($this->planKey($user), $swapped->toArray() + [
            'origin' => [$originLat, $originLng],
            'rejected' => $rejectedMap,
        ], now()->addHours(self::PLAN_TTL_HOURS));

        return response()->json(['plan' => $swapped->toArray()]);
    }

    private function planKey(User $user): string
    {
        return "composer:plan:{$user->id}";
    }

    private function rainExpected(): bool
    {
        try {
            return (bool) (app(WeatherService::class)->getForecast()['rain_starts'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function origin(User $user): array
    {
        $home = $user->places()->where('category', 'home')->first();
        if ($home?->lat && $home?->lng) {
            return [(float) $home->lat, (float) $home->lng];
        }

        return [50.9375, 6.9603]; // Cologne centre
    }

    /**
     * Rebuild a Plan from its stored array using fresh candidate
     * snapshots (so swapped-in venues carry current data).
     *
     * @param  array<string, mixed>  $stored
     */
    private function hydratePlan(array $stored, CandidateRepository $candidates): Plan
    {
        $constraints = Constraints::fromArray($stored['constraints']);
        $pool = collect($candidates->candidatesFor($constraints))->keyBy('id');

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
