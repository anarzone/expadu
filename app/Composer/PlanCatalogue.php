<?php

namespace App\Composer;

use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Rechecks catalogue claims without rewriting a person's saved history. */
class PlanCatalogue
{
    private array $projections = [];

    public function __construct(
        private CandidateRepository $candidates,
        private FeasibilityFilter $filter,
        private PlaceIdentity $identity,
    ) {}

    public function project(array $plan): array
    {
        $key = hash('sha256', json_encode($plan));
        if (isset($this->projections[$key])) {
            return $this->projections[$key];
        }
        $plan = $this->identity->normalizePlan($plan);
        $constraints = isset($plan['constraints']['window_start'], $plan['constraints']['window_end'])
            ? Constraints::fromArray($plan['constraints']) : null;
        [$lat, $lng] = $plan['origin'] ?? [50.9375, 6.9603];
        $groups = [];
        foreach ($plan['slots'] ?? [] as $slot) {
            if ($this->isCatalogueSlot($slot) && isset($slot['start_at'])) {
                $day = CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin')->toDateString();
                $groups[$day][] = $slot['id'];
            }
        }
        $candidates = [];
        if ($constraints !== null) {
            foreach ($groups as $day => $ids) {
                foreach ($this->candidates->byIds($ids, CarbonImmutable::parse($day, 'Europe/Berlin'), $lat, $lng) as $candidate) {
                    $candidates[$day][$candidate->id] = $candidate;
                }
            }
        }
        foreach ($plan['slots'] ?? [] as $index => $slot) {
            if (! $this->isCatalogueSlot($slot)) {
                continue;
            }
            $start = isset($slot['start_at']) ? CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin') : null;
            $end = isset($slot['end_at']) ? CarbonImmutable::parse($slot['end_at'])->setTimezone('Europe/Berlin') : null;
            $candidate = $start === null ? null : ($candidates[$start->toDateString()][$slot['id']] ?? null);
            if ($candidate === null || $constraints === null || $start === null || $end === null
                || ! $this->available($candidate, $constraints, $start, $end)) {
                $plan['slots'][$index] = array_replace($slot, [
                    'recommendation_status' => 'unavailable',
                    'availability_message' => 'This saved item needs review. Its current availability or facts no longer match this plan.',
                    'cost_tier' => 'unknown',
                    'closes_at' => null,
                    'why' => null,
                    'place_facts' => null,
                    'source_facts' => [],
                ]);

                continue;
            }
            $visit = new PlanSlot($candidate, $start, $end, (int) ($slot['travel_min_from_previous'] ?? 0));
            $fresh = (new PlanSlot($candidate, $start, $end, $visit->travelMinFromPrevious, PlanNarrator::for($visit, null, false)))->toArray();
            $plan['slots'][$index] = array_replace($slot, $fresh, [
                'name' => $slot['name'] ?? $fresh['name'],
                'recommendation_status' => 'available',
                'availability_message' => null,
            ]);
        }

        return $this->projections[$key] = $plan;
    }

    /** New and rescheduled selections must qualify; unchanged saved items stay retained. */
    public function assertChangesAvailable(array $plan, ?array $before = null): void
    {
        $projected = $this->project($plan);
        $retained = collect($this->identity->normalizePlan($before ?? [])['slots'] ?? []);
        foreach ($projected['slots'] ?? [] as $slot) {
            if (($slot['recommendation_status'] ?? null) !== 'unavailable') {
                continue;
            }
            $unchanged = $retained->contains(fn (array $old): bool => $old['id'] === $slot['id']
                && ($old['start_at'] ?? null) === ($slot['start_at'] ?? null)
                && ($old['end_at'] ?? null) === ($slot['end_at'] ?? null));
            if (! $unchanged) {
                throw ValidationException::withMessages(['action' => 'A selected place or event is no longer available for this plan. Review or remove it before applying changes.']);
            }
        }
    }

    public function available(Candidate $candidate, Constraints $constraints, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (! $this->filter->matchesSelection($constraints, $candidate, $start, $end)) {
            return false;
        }
        if ($candidate->fixedStart !== null) {
            return $candidate->fixedStart->equalTo($start)
                && $candidate->fixedStart->addMinutes($candidate->typicalDurationMin)->equalTo($end);
        }

        return $candidate->coversVisit($start, $end);
    }

    private function isCatalogueSlot(array $slot): bool
    {
        return is_string($slot['id'] ?? null)
            && (str_starts_with($slot['id'], 'spot:') || str_starts_with($slot['id'], 'event:'));
    }
}
