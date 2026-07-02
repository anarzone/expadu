<?php

namespace App\Composer;

use App\Composer\Contracts\EstimatesTravel;
use App\Enums\SpotCategory;
use Carbon\CarbonImmutable;

/**
 * Greedy plan composition: anchor fixed-time events first, then fill
 * the remaining gaps left→right, re-scoring the whole candidate set
 * against the updated plan state (current position, used categories,
 * remaining time) after each placement. Deterministic for fixed input.
 */
class SlotFiller
{
    public const MAX_SLOTS_PER_DAY = 6;

    /**
     * Two spots that share a visible name collapse to one only when they sit
     * this close — a single facility OSM split across polygons (a park, a
     * sports ground). Generic names like "Bolzplatz"/"Spielplatz" repeat all
     * over the city; those are genuinely different places and must both show.
     */
    private const SAME_PLACE_METERS = 250.0;

    public function __construct(
        private readonly PlanScorer $scorer,
        private readonly EstimatesTravel $travel,
    ) {}

    /**
     * @param  list<Candidate>  $feasible
     * @param  list<Role>|null  $roles  explicit roles (a category-led "anchor + around it" day); null = derive from the archetype
     * @param  int  $sameCoarseCap  max slots of one coarse category in the day (itinerary variety); PHP_INT_MAX = no cap (browse)
     */
    public function fill(
        Constraints $constraints,
        array $feasible,
        ScoringContext $context,
        float $originLat,
        float $originLng,
        ?array $roles = null,
        int $sameCoarseCap = PHP_INT_MAX,
    ): Plan {
        $slots = [];
        $used = [];
        // Visible-name de-dup, location-aware: a name placed once blocks another
        // row of the same name only when it sits within SAME_PLACE_METERS (one
        // facility OSM-split across polygons). Two "Bolzplatz" in different
        // Veedels are distinct places and both stay eligible. Keyed by name →
        // the coordinates already placed under it.
        $usedNames = [];

        // 0. Pinned "plan around this" picks are placed first, chained from
        //    the origin, so the user's explicit choices win the window before
        //    any auto-anchored event can crowd them out.
        $pinCursor = $constraints->windowStart;
        $pinLat = $originLat;
        $pinLng = $originLng;
        foreach ($feasible as $pin) {
            if (count($slots) >= self::MAX_SLOTS_PER_DAY) {
                break;
            }
            if ($pin->isFixedTime() || isset($used[$pin->id]) || ! in_array($pin->id, $context->pinnedIds, true)) {
                continue;
            }

            $travelMin = $this->travel->minutesBetween($pinLat, $pinLng, $pin->lat, $pin->lng);
            $start = $pinCursor->addMinutes($travelMin);
            if ($pin->opensAt !== null && $pin->opensAt->greaterThan($start)) {
                $start = $pin->opensAt;
            }
            $end = $start->addMinutes($pin->typicalDurationMin);
            if ($end->greaterThan($constraints->windowEnd)) {
                continue; // genuinely no room, even for a pin
            }

            $slots[] = new PlanSlot($pin, $start, $end, $travelMin);
            $used[$pin->id] = true;
            $usedNames[$this->nameKey($pin)][] = [$pin->lat, $pin->lng];
            $pinCursor = $end;
            $pinLat = $pin->lat;
            $pinLng = $pin->lng;
        }

        // 1. Anchor fixed-time candidates. Appointments are sacred, so they
        //    claim their slot before curated events; then earliest first.
        $fixed = array_filter($feasible, fn (Candidate $c) => $c->isFixedTime());
        usort($fixed, function (Candidate $a, Candidate $b) {
            if ($a->isAppointment() !== $b->isAppointment()) {
                return $a->isAppointment() ? -1 : 1;
            }

            return $a->fixedStart <=> $b->fixedStart;
        });

        foreach ($fixed as $anchor) {
            $start = $anchor->fixedStart;
            $end = $start->addMinutes($anchor->typicalDurationMin);
            if ($this->overlapsAny($slots, $start, $end)) {
                continue;
            }
            $slots[] = new PlanSlot($anchor, $start, $end, 0);
            $used[$anchor->id] = true;
            $usedNames[$this->nameKey($anchor)][] = [$anchor->lat, $anchor->lng];
            if (count($slots) >= self::MAX_SLOTS_PER_DAY) {
                break;
            }
        }

        usort($slots, fn (PlanSlot $a, PlanSlot $b) => $a->startAt <=> $b->startAt);

        // 2. Fill the roles in order, left→right. Explicit roles (a category-led
        //    "anchor + around it" day) win; otherwise the archetype/vibe shapes
        //    it, with Balanced — one permissive role that fills the window — the
        //    default greedy behaviour.
        $archetype = $constraints->archetype ?? Archetype::forVibe($constraints->vibe) ?? Archetype::Balanced;
        $roleList = $roles ?? $archetype->roles($constraints->windowMinutes());
        $targetVeedel = $roles === null && $archetype->singleVeedel() ? ($context->preferredAreas[0] ?? null) : null;

        $beforeRoles = count($slots);
        $this->fillRoles($roleList, $feasible, $slots, $used, $usedNames, $constraints, $context, $originLat, $originLng, $targetVeedel, $sameCoarseCap);

        // Graceful degradation: a shaped day (explicit roles, or a non-Balanced
        // archetype) whose roles match nothing in the filtered pool — "make a day
        // of it" over a pitches-only result, or "explore a Veedel" with no spots
        // there — would dead-end at "nothing fits". Fall back to a permissive
        // fill (any feasible category, anywhere, still capped) so the user always
        // gets a real plan, flagged as relaxed so the response can say so.
        $shaped = $roles !== null || $archetype !== Archetype::Balanced;
        $relaxed = false;
        if ($shaped && count($slots) === $beforeRoles) {
            $this->fillRoles([new Role([], self::MAX_SLOTS_PER_DAY)], $feasible, $slots, $used, $usedNames, $constraints, $context, $originLat, $originLng, null, $sameCoarseCap);
            $relaxed = count($slots) > $beforeRoles;
        }

        return new Plan(
            $constraints,
            PlanNarrator::narrate($this->bufferAnchors(array_values($slots)), $context->rainExpected),
            $relaxed,
        );
    }

    /**
     * Fill an ordered list of roles, left→right, chaining the cursor across
     * them from the window start (so a hero sets up the meal that follows).
     * Mutates $slots / $used / $usedNames in place.
     *
     * @param  list<Role>  $roles
     * @param  list<Candidate>  $feasible
     * @param  list<PlanSlot>  $slots
     * @param  array<string, bool>  $used
     * @param  array<string, list<array{0: float, 1: float}>>  $usedNames
     */
    private function fillRoles(
        array $roles,
        array $feasible,
        array &$slots,
        array &$used,
        array &$usedNames,
        Constraints $constraints,
        ScoringContext $context,
        float $originLat,
        float $originLng,
        ?string $targetVeedel,
        int $sameCoarseCap = PHP_INT_MAX,
    ): void {
        $cursor = $constraints->windowStart;
        $cursorLat = $originLat;
        $cursorLng = $originLng;

        foreach ($roles as $role) {
            $placed = 0;

            while ($placed < $role->count && count($slots) < self::MAX_SLOTS_PER_DAY) {
                [$cursor, $cursorLat, $cursorLng] = $this->advancePastAnchors($slots, $cursor, $cursorLat, $cursorLng);

                $gapEnd = $this->nextAnchorStart($slots, $cursor) ?? $constraints->windowEnd;
                $best = $this->bestFor($feasible, $used, $usedNames, $slots, $cursor, $cursorLat, $cursorLng, $gapEnd, $context, $role->categories, $targetVeedel, $sameCoarseCap);

                if ($best === null) {
                    // Nothing for this role in this gap — jump past the next
                    // anchor and retry; with no anchor ahead, the role is done.
                    $nextAnchor = $this->nextAnchorStart($slots, $cursor);
                    if ($nextAnchor === null) {
                        break;
                    }
                    $cursor = $this->slotEndingAfter($slots, $nextAnchor);

                    continue;
                }

                [$candidate, $travelMin] = $best;
                $start = $cursor->addMinutes($travelMin);
                if ($candidate->opensAt !== null && $candidate->opensAt->greaterThan($start)) {
                    $start = $candidate->opensAt;
                }
                $end = $start->addMinutes($candidate->typicalDurationMin);

                $slots[] = new PlanSlot($candidate, $start, $end, $travelMin);
                $used[$candidate->id] = true;
                $usedNames[$this->nameKey($candidate)][] = [$candidate->lat, $candidate->lng];
                usort($slots, fn (PlanSlot $a, PlanSlot $b) => $a->startAt <=> $b->startAt);

                $cursor = $end;
                $cursorLat = $candidate->lat;
                $cursorLng = $candidate->lng;
                $placed++;
            }
        }
    }

    /**
     * Anchors are placed before the greedy fill, so their travel-from-
     * previous is unknown at placement time. Once everything is laid out,
     * annotate each anchor with the trip in from the slot before it — this
     * is what powers the "leave {place} by 13:38" line on appointments.
     *
     * @param  list<PlanSlot>  $slots
     * @return list<PlanSlot>
     */
    private function bufferAnchors(array $slots): array
    {
        foreach ($slots as $i => $slot) {
            if ($i === 0 || ! $slot->candidate->isFixedTime() || $slot->travelMinFromPrevious > 0) {
                continue;
            }

            $previous = $slots[$i - 1];
            $travelMin = $this->travel->minutesBetween(
                $previous->candidate->lat,
                $previous->candidate->lng,
                $slot->candidate->lat,
                $slot->candidate->lng,
            );

            if ($travelMin > 0) {
                $slots[$i] = new PlanSlot($slot->candidate, $slot->startAt, $slot->endAt, $travelMin);
            }
        }

        return $slots;
    }

    /**
     * Best non-fixed candidate that fits between cursor and gapEnd, optionally
     * restricted to a role's categories and a single Veedel.
     *
     * @param  list<Candidate>  $feasible
     * @param  array<string, bool>  $used
     * @param  array<string, list<array{0: float, 1: float}>>  $usedNames  placed coordinates keyed by visible name
     * @param  list<PlanSlot>  $slots
     * @param  list<string>  $allowedCategories  empty = any category
     * @return array{0: Candidate, 1: int}|null
     */
    private function bestFor(
        array $feasible,
        array $used,
        array $usedNames,
        array $slots,
        CarbonImmutable $cursor,
        float $cursorLat,
        float $cursorLng,
        CarbonImmutable $gapEnd,
        ScoringContext $context,
        array $allowedCategories = [],
        ?string $targetVeedel = null,
        int $sameCoarseCap = PHP_INT_MAX,
    ): ?array {
        $best = null;
        $bestScore = -INF;

        foreach ($feasible as $candidate) {
            if ($candidate->isFixedTime() || isset($used[$candidate->id])) {
                continue;
            }
            // Skip a venue whose visible name is already placed nearby (one
            // facility split across OSM polygons) — but allow a same-named spot
            // elsewhere in the city (a different Bolzplatz in another Veedel).
            if ($this->isVisualDuplicate($candidate, $usedNames)) {
                continue;
            }
            // Role restrictions: a category set (empty = any) and, for
            // explore-a-Veedel, a single neighbourhood.
            if ($allowedCategories !== [] && ! in_array($candidate->category, $allowedCategories, true)) {
                continue;
            }
            if ($targetVeedel !== null && $candidate->veedel !== $targetVeedel) {
                continue;
            }
            // Repeat cap: don't stack more than N of one coarse category in a
            // single itinerary — a day isn't six football pitches. Browse mode
            // passes no cap, so "show me pitches" still lists many.
            if ($sameCoarseCap !== PHP_INT_MAX && $this->coarsePlaced($slots, $candidate) >= $sameCoarseCap) {
                continue;
            }

            $travelMin = $this->travel->minutesBetween($cursorLat, $cursorLng, $candidate->lat, $candidate->lng);
            $start = $cursor->addMinutes($travelMin);
            if ($candidate->opensAt !== null && $candidate->opensAt->greaterThan($start)) {
                $start = $candidate->opensAt;
            }
            $end = $start->addMinutes($candidate->typicalDurationMin);

            if ($end->greaterThan($gapEnd)) {
                continue;
            }
            if ($candidate->closesAt !== null && $end->greaterThan($candidate->closesAt)) {
                continue;
            }

            $score = $this->scorer->score($candidate, $slots, $cursor, $cursorLat, $cursorLng, $context);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$candidate, $travelMin];
            }
        }

        return $best;
    }

    /**
     * Move the cursor (and its lat/lng) to the end of any anchored slot it
     * currently sits inside, so the next pick is chained from there.
     *
     * @param  list<PlanSlot>  $slots
     * @return array{0: CarbonImmutable, 1: float, 2: float}
     */
    private function advancePastAnchors(array $slots, CarbonImmutable $cursor, float $lat, float $lng): array
    {
        foreach ($slots as $slot) {
            if ($slot->startAt->lessThanOrEqualTo($cursor) && $slot->endAt->greaterThan($cursor)) {
                $cursor = $slot->endAt;
                $lat = $slot->candidate->lat;
                $lng = $slot->candidate->lng;
            }
        }

        return [$cursor, $lat, $lng];
    }

    /**
     * @param  list<PlanSlot>  $slots
     */
    private function overlapsAny(array $slots, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($slots as $slot) {
            if ($start->lessThan($slot->endAt) && $end->greaterThan($slot->startAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PlanSlot>  $slots
     */
    private function nextAnchorStart(array $slots, CarbonImmutable $after): ?CarbonImmutable
    {
        $next = null;
        foreach ($slots as $slot) {
            if ($slot->startAt->greaterThan($after) && ($next === null || $slot->startAt->lessThan($next))) {
                $next = $slot->startAt;
            }
        }

        return $next;
    }

    /**
     * @param  list<PlanSlot>  $slots
     */
    private function slotEndingAfter(array $slots, CarbonImmutable $startAt): CarbonImmutable
    {
        foreach ($slots as $slot) {
            if ($slot->startAt->equalTo($startAt)) {
                return $slot->endAt;
            }
        }

        return $startAt;
    }

    /**
     * The key a candidate de-dups on by sight: its visible name, lower-cased.
     * Falls back to the id for the rare nameless candidate.
     */
    private function nameKey(Candidate $candidate): string
    {
        $name = mb_strtolower(trim($candidate->name));

        return $name !== '' ? $name : 'id:'.$candidate->id;
    }

    /**
     * True when a spot of this visible name has already been placed close enough
     * to be the same physical place (an OSM polygon split). A same-named spot
     * farther than SAME_PLACE_METERS is a genuinely different venue and passes.
     *
     * @param  array<string, list<array{0: float, 1: float}>>  $usedNames
     */
    private function isVisualDuplicate(Candidate $candidate, array $usedNames): bool
    {
        foreach ($usedNames[$this->nameKey($candidate)] ?? [] as [$lat, $lng]) {
            if ($this->metersBetween($candidate->lat, $candidate->lng, $lat, $lng) <= self::SAME_PLACE_METERS) {
                return true;
            }
        }

        return false;
    }

    /** Great-circle distance in metres between two coordinates. */
    private function metersBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6_371_000.0 * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * How many already-placed slots share this candidate's coarse bucket — the
     * count the repeat cap reads (so "pitch" + "basketball" don't both count as
     * one, but two football pitches do).
     *
     * @param  list<PlanSlot>  $slots
     */
    private function coarsePlaced(array $slots, Candidate $candidate): int
    {
        $coarse = $this->coarseOf($candidate);

        return count(array_filter($slots, fn (PlanSlot $slot) => $this->coarseOf($slot->candidate) === $coarse));
    }

    /** A candidate's coarse Places bucket, falling back to its raw category (events/appointments). */
    private function coarseOf(Candidate $candidate): string
    {
        return SpotCategory::tryFrom($candidate->category)?->coarse() ?? $candidate->category;
    }
}
