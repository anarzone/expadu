<?php

use App\Composer\Archetype;
use App\Composer\Candidate;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Composer\PlanNarrator;
use App\Composer\PlanScorer;
use App\Composer\PlanSlot;
use App\Composer\ScoringContext;
use App\Composer\SlotFiller;
use App\Composer\Swapper;
use App\Composer\TravelEstimator;
use Carbon\CarbonImmutable;

/**
 * Pure-pipeline tests: no DB, no network, fully deterministic fixtures.
 */
function saturday(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-06-13 {$time}", 'Europe/Berlin');
}

function makeCandidate(array $overrides = []): Candidate
{
    $id = $overrides['id'] ?? 'spot:1';

    $defaults = [
        'id' => $id,
        'type' => 'spot',
        // Unique by id so a multi-candidate plan never reads as duplicates —
        // the SlotFiller de-dups by visible name, mirroring real OSM data.
        'name' => 'Spot '.$id,
        'lat' => 50.9442,
        'lng' => 6.9329,
        'veedel' => 'Neustadt-Nord',
        'category' => 'park',
        'outdoor' => true,
        'typicalDurationMin' => 75,
        'costTier' => 'free',
        'opensAt' => null,
        'closesAt' => null,
        'fixedStart' => null,
    ];

    return new Candidate(...array_merge($defaults, $overrides));
}

function defaultConstraints(): Constraints
{
    return new Constraints(
        windowStart: saturday('14:00'),
        windowEnd: saturday('20:00'),
        areas: ['Neustadt-Nord', 'Ehrenfeld'],
    );
}

function neutralContext(): ScoringContext
{
    return new ScoringContext(rainExpected: false, preferredAreas: ['Neustadt-Nord', 'Ehrenfeld']);
}

function filler(): SlotFiller
{
    return new SlotFiller(new PlanScorer(new TravelEstimator), new TravelEstimator);
}

// ── Archetypes ─────────────────────────────────────────────────────────

test('explore-a-Veedel clusters every pick into one neighbourhood', function () {
    $constraints = new Constraints(
        windowStart: saturday('14:00'),
        windowEnd: saturday('20:00'),
        areas: ['Neustadt-Nord'],
        archetype: Archetype::ExploreVeedel,
    );
    $feasible = [
        makeCandidate(['id' => 'spot:a', 'category' => 'park', 'veedel' => 'Neustadt-Nord', 'lat' => 50.944, 'lng' => 6.933]),
        makeCandidate(['id' => 'spot:b', 'category' => 'cafe', 'outdoor' => false, 'veedel' => 'Neustadt-Nord', 'lat' => 50.945, 'lng' => 6.934]),
        makeCandidate(['id' => 'spot:far', 'category' => 'park', 'veedel' => 'Porz', 'lat' => 50.88, 'lng' => 7.05]),
    ];
    $context = new ScoringContext(rainExpected: false, preferredAreas: ['Neustadt-Nord']);

    $plan = filler()->fill($constraints, $feasible, $context, 50.944, 6.933);

    expect($plan->slots)->not->toBeEmpty();
    expect(array_map(fn ($s) => $s->candidate->veedel, $plan->slots))->not->toContain('Porz');
});

test('chill-nearby only places low-effort categories', function () {
    $constraints = new Constraints(
        windowStart: saturday('14:00'),
        windowEnd: saturday('20:00'),
        archetype: Archetype::ChillNearby,
    );
    $feasible = [
        makeCandidate(['id' => 'spot:museum', 'category' => 'museum', 'outdoor' => false]),
        makeCandidate(['id' => 'spot:park', 'category' => 'park']),
        makeCandidate(['id' => 'spot:cafe', 'category' => 'cafe', 'outdoor' => false, 'lat' => 50.945, 'lng' => 6.934]),
    ];

    $plan = filler()->fill($constraints, $feasible, neutralContext(), 50.944, 6.933);

    expect(array_map(fn ($s) => $s->candidate->category, $plan->slots))->not->toContain('museum');
    expect(count($plan->slots))->toBeLessThanOrEqual(2); // two roles
});

test('one-main-plus-supports leads with the landmark hero', function () {
    $constraints = new Constraints(
        windowStart: saturday('14:00'),
        windowEnd: saturday('20:00'),
        archetype: Archetype::OneMainPlusSupports,
    );
    $feasible = [
        makeCandidate(['id' => 'spot:plain', 'category' => 'museum', 'outdoor' => false, 'isLandmark' => false, 'lat' => 50.9445, 'lng' => 6.9335]),
        makeCandidate(['id' => 'spot:famous', 'category' => 'museum', 'outdoor' => false, 'isLandmark' => true, 'lat' => 50.944, 'lng' => 6.933]),
        makeCandidate(['id' => 'spot:cafe', 'category' => 'cafe', 'outdoor' => false, 'lat' => 50.945, 'lng' => 6.934]),
    ];

    $plan = filler()->fill($constraints, $feasible, neutralContext(), 50.944, 6.933);

    expect($plan->slots[0]->candidate->id)->toBe('spot:famous'); // the notable place is the hero
});

test('a vibe implies an archetype when none is chosen', function () {
    expect(Archetype::forVibe('chill'))->toBe(Archetype::ChillNearby)
        ->and(Archetype::forVibe('active'))->toBe(Archetype::MakeADayOfIt)
        ->and(Archetype::forVibe(null))->toBeNull();
});

test('interest affinity lifts a candidate score', function () {
    $scorer = new PlanScorer(new TravelEstimator);
    $cursor = saturday('15:00');
    $museum = makeCandidate(['category' => 'museum', 'outdoor' => false]);

    $neutral = $scorer->score($museum, [], $cursor, 50.9442, 6.9329, neutralContext());
    $interested = $scorer->score(
        $museum,
        [],
        $cursor,
        50.9442,
        6.9329,
        new ScoringContext(rainExpected: false, preferredAreas: [], affinity: ['museum' => 1.0]),
    );

    expect($interested)->toBeGreaterThan($neutral);
});

// ── Soft timing + narrator ─────────────────────────────────────────────

test('a slot exposes a soft time band and rough duration', function () {
    $morning = new PlanSlot(makeCandidate(), saturday('10:00'), saturday('11:30'), 0);
    $afternoon = new PlanSlot(makeCandidate(), saturday('15:00'), saturday('16:00'), 0);

    expect($morning->toArray()['band'])->toBe('Morning')
        ->and($morning->toArray()['duration_label'])->toBe('~1½h')
        ->and($afternoon->toArray()['band'])->toBe('Afternoon')
        ->and($afternoon->toArray()['duration_label'])->toBe('~1h');
});

test('the narrator explains a pick from the data we have', function () {
    $indoorLandmark = new PlanSlot(
        makeCandidate(['category' => 'museum', 'outdoor' => false, 'isLandmark' => true, 'closesAt' => saturday('18:00')]),
        saturday('14:00'),
        saturday('16:00'),
        0,
    );
    $why = PlanNarrator::for($indoorLandmark, null, rain: true);
    expect($why)->toContain('⭐ landmark')->toContain('indoor — beats the rain')->toContain('open till 18:00');

    $prev = new PlanSlot(makeCandidate(['veedel' => 'Ehrenfeld']), saturday('14:00'), saturday('15:00'), 0);
    $next = new PlanSlot(makeCandidate(['id' => 'spot:2', 'veedel' => 'Ehrenfeld']), saturday('15:10'), saturday('16:10'), 6);
    expect(PlanNarrator::for($next, $prev, rain: false))->toContain('🚶 6 min · same area');
});

test('the narrator gives an appointment a leave-by line', function () {
    $prev = new PlanSlot(makeCandidate(['name' => 'Café Franck']), saturday('13:00'), saturday('14:00'), 0);
    $appt = new PlanSlot(
        makeCandidate(['id' => 'appointment:1', 'type' => 'appointment', 'name' => 'Ausländerbehörde', 'fixedStart' => saturday('15:00')]),
        saturday('15:00'),
        saturday('15:30'),
        11,
    );

    expect(PlanNarrator::for($appt, $prev, rain: false))->toBe('Leave Café Franck by 14:49');
});

test('narrate fills the why line for the whole sequence', function () {
    $slots = [
        new PlanSlot(makeCandidate(['category' => 'museum', 'outdoor' => false, 'isLandmark' => true]), saturday('14:00'), saturday('16:00'), 0),
        new PlanSlot(makeCandidate(['id' => 'spot:2', 'category' => 'cafe', 'outdoor' => false, 'veedel' => 'Neustadt-Nord']), saturday('16:10'), saturday('17:10'), 6),
    ];

    $narrated = PlanNarrator::narrate($slots, false);

    expect($narrated[0]->why)->toContain('⭐ landmark');
    expect($narrated[1]->why)->toContain('🚶 6 min');
});

// ── FeasibilityFilter ──────────────────────────────────────────────────

test('feasibility excludes venues closed during the window', function () {
    $filter = new FeasibilityFilter;

    $open = makeCandidate(['id' => 'spot:open']);
    $closesEarly = makeCandidate([
        'id' => 'spot:early',
        'closesAt' => saturday('14:30'),  // only 30 min inside a 75-min visit
    ]);

    $result = $filter->filter(defaultConstraints(), [$open, $closesEarly]);

    expect(array_map(fn ($c) => $c->id, $result))->toBe(['spot:open']);
});

test('feasibility excludes paid venues from a free-budget plan', function () {
    $filter = new FeasibilityFilter;
    $constraints = new Constraints(
        windowStart: saturday('14:00'),
        windowEnd: saturday('20:00'),
        budget: 'free',
    );

    $park = makeCandidate(['id' => 'spot:park', 'costTier' => 'free']);
    $restaurant = makeCandidate(['id' => 'spot:restaurant', 'category' => 'restaurant', 'costTier' => 'normal', 'outdoor' => false]);

    $result = $filter->filter($constraints, [$park, $restaurant]);

    expect(array_map(fn ($c) => $c->id, $result))->toBe(['spot:park']);
});

test('feasibility excludes events outside the window', function () {
    $filter = new FeasibilityFilter;

    $inside = makeCandidate(['id' => 'event:in', 'type' => 'event', 'fixedStart' => saturday('18:00'), 'typicalDurationMin' => 90]);
    $tooLate = makeCandidate(['id' => 'event:late', 'type' => 'event', 'fixedStart' => saturday('19:30'), 'typicalDurationMin' => 90]);

    $result = $filter->filter(defaultConstraints(), [$inside, $tooLate]);

    expect(array_map(fn ($c) => $c->id, $result))->toBe(['event:in']);
});

test('a coarse category constraint admits its fine venues', function () {
    $filter = new FeasibilityFilter;
    $museum = makeCandidate(['id' => 'spot:m', 'category' => 'museum', 'outdoor' => false]);
    $park = makeCandidate(['id' => 'spot:p', 'category' => 'park']);

    // "culture" is a coarse bucket — museum/gallery/attraction/zoo roll into it.
    $culture = new Constraints(windowStart: saturday('14:00'), windowEnd: saturday('20:00'), categories: ['culture']);
    expect(array_map(fn ($c) => $c->id, $filter->filter($culture, [$museum, $park])))->toBe(['spot:m']);

    // A fine value still matches itself.
    $fine = new Constraints(windowStart: saturday('14:00'), windowEnd: saturday('20:00'), categories: ['museum']);
    expect(array_map(fn ($c) => $c->id, $filter->filter($fine, [$museum, $park])))->toBe(['spot:m']);
});

test('a plan never places two venues that read by the same name', function () {
    // Two distinct rows sharing a name (a park split across OSM polygons).
    $candidates = [
        makeCandidate(['id' => 'spot:g1', 'name' => 'Grüngürtel', 'category' => 'park', 'lat' => 50.9442, 'lng' => 6.9329]),
        makeCandidate(['id' => 'spot:g2', 'name' => 'Grüngürtel', 'category' => 'park', 'lat' => 50.9450, 'lng' => 6.9340]),
        makeCandidate(['id' => 'spot:other', 'name' => 'Rheinpark', 'category' => 'park', 'lat' => 50.9470, 'lng' => 6.9360]),
    ];

    $plan = filler()->fill(defaultConstraints(), $candidates, neutralContext(), 50.9442, 6.9329);
    $names = collect($plan->slots)->map(fn ($s) => $s->candidate->name);

    expect($names->count())->toBe($names->unique()->count())   // no repeated name
        ->and($names)->toContain('Grüngürtel');                // one of the two, not both
});

// ── PlanScorer ─────────────────────────────────────────────────────────

test('variety penalty is monotonic in repeated categories', function () {
    $scorer = new PlanScorer(new TravelEstimator);
    $candidate = makeCandidate(['category' => 'cafe', 'outdoor' => false]);
    $cursor = saturday('15:00');

    $slotOfSameCategory = new PlanSlot(
        makeCandidate(['id' => 'spot:prior', 'category' => 'cafe', 'outdoor' => false]),
        saturday('14:00'),
        saturday('15:00'),
        0,
    );

    $zero = $scorer->score($candidate, [], $cursor, 50.9442, 6.9329, neutralContext());
    $one = $scorer->score($candidate, [$slotOfSameCategory], $cursor, 50.9442, 6.9329, neutralContext());
    $two = $scorer->score($candidate, [$slotOfSameCategory, $slotOfSameCategory], $cursor, 50.9442, 6.9329, neutralContext());

    expect($zero)->toBeGreaterThan($one);
    expect($one)->toBeGreaterThan($two);
});

test('rain kills outdoor candidates relative to indoor', function () {
    $scorer = new PlanScorer(new TravelEstimator);
    $rainy = new ScoringContext(rainExpected: true, preferredAreas: []);

    $park = makeCandidate(['outdoor' => true]);
    $cafe = makeCandidate(['id' => 'spot:2', 'category' => 'cafe', 'outdoor' => false]);

    $parkScore = $scorer->score($park, [], saturday('15:00'), 50.9442, 6.9329, $rainy);
    $cafeScore = $scorer->score($cafe, [], saturday('15:00'), 50.9442, 6.9329, $rainy);

    expect($cafeScore)->toBeGreaterThan($parkScore);
});

test('closes-soon boosts a venue that will not be possible later', function () {
    $scorer = new PlanScorer(new TravelEstimator);

    $closingSoon = makeCandidate(['id' => 'spot:closing', 'closesAt' => saturday('16:00')]);
    $openLate = makeCandidate(['id' => 'spot:late']);

    $soonScore = $scorer->score($closingSoon, [], saturday('15:00'), 50.9442, 6.9329, neutralContext());
    $lateScore = $scorer->score($openLate, [], saturday('15:00'), 50.9442, 6.9329, neutralContext());

    expect($soonScore)->toBeGreaterThan($lateScore);
});

test('companion fit favours kid-friendly places over a coworking space', function () {
    $scorer = new PlanScorer(new TravelEstimator);
    $kids = new ScoringContext(rainExpected: false, preferredAreas: [], companions: 'kids');

    $playground = makeCandidate(['id' => 'spot:pg', 'category' => 'playground']);
    $coworking = makeCandidate(['id' => 'spot:cw', 'category' => 'coworking', 'outdoor' => false]);

    $pg = $scorer->score($playground, [], saturday('15:00'), 50.9442, 6.9329, $kids);
    $cw = $scorer->score($coworking, [], saturday('15:00'), 50.9442, 6.9329, $kids);

    expect($pg)->toBeGreaterThan($cw);
});

test('companions other than kids do not change a score', function () {
    $scorer = new PlanScorer(new TravelEstimator);
    $candidate = makeCandidate(['id' => 'spot:x', 'category' => 'cafe', 'outdoor' => false]);

    $none = neutralContext();
    $friends = new ScoringContext(rainExpected: false, preferredAreas: ['Neustadt-Nord', 'Ehrenfeld'], companions: 'friends');

    $a = $scorer->score($candidate, [], saturday('15:00'), 50.9442, 6.9329, $none);
    $b = $scorer->score($candidate, [], saturday('15:00'), 50.9442, 6.9329, $friends);

    expect($a)->toBe($b);
});

test('activities outrank a café at equal distance', function () {
    $scorer = new PlanScorer(new TravelEstimator);
    $playground = makeCandidate(['id' => 'spot:pg', 'category' => 'playground']);
    $cafe = makeCandidate(['id' => 'spot:cf', 'category' => 'cafe', 'outdoor' => false]);

    $pg = $scorer->score($playground, [], saturday('15:00'), 50.9442, 6.9329, neutralContext());
    $cf = $scorer->score($cafe, [], saturday('15:00'), 50.9442, 6.9329, neutralContext());

    expect($pg)->toBeGreaterThan($cf);
});

test('a kids plan excludes bars at the feasibility stage', function () {
    $filter = new FeasibilityFilter;
    $constraints = new Constraints(
        windowStart: saturday('14:00'),
        windowEnd: saturday('20:00'),
        companions: 'kids',
    );

    $bar = makeCandidate(['id' => 'spot:bar', 'category' => 'bar', 'outdoor' => false, 'costTier' => 'normal']);
    $park = makeCandidate(['id' => 'spot:park', 'category' => 'park']);

    $result = $filter->filter($constraints, [$bar, $park]);

    expect(array_map(fn ($c) => $c->id, $result))->toBe(['spot:park']);
});

// ── SlotFiller ─────────────────────────────────────────────────────────

function fixtureCandidates(): array
{
    return [
        makeCandidate(['id' => 'spot:park', 'category' => 'park', 'lat' => 50.9442, 'lng' => 6.9329]),
        makeCandidate(['id' => 'spot:cafe', 'category' => 'cafe', 'outdoor' => false, 'typicalDurationMin' => 60, 'costTier' => 'low', 'lat' => 50.9485, 'lng' => 6.9230, 'veedel' => 'Ehrenfeld']),
        makeCandidate(['id' => 'spot:museum', 'category' => 'culture', 'outdoor' => false, 'typicalDurationMin' => 90, 'costTier' => 'normal', 'lat' => 50.9403, 'lng' => 6.9610, 'closesAt' => saturday('18:00'), 'veedel' => 'Altstadt-Nord']),
        makeCandidate(['id' => 'event:mixer', 'type' => 'event', 'category' => 'community', 'outdoor' => false, 'fixedStart' => saturday('18:00'), 'typicalDurationMin' => 120, 'lat' => 50.9480, 'lng' => 6.9450, 'veedel' => null]),
    ];
}

test('fixed-time events are anchored exactly at their start', function () {
    $filler = new SlotFiller(new PlanScorer(new TravelEstimator), new TravelEstimator);

    $plan = $filler->fill(defaultConstraints(), fixtureCandidates(), neutralContext(), 50.9442, 6.9329);

    $eventSlot = collect($plan->slots)->firstWhere(fn ($s) => $s->candidate->id === 'event:mixer');
    expect($eventSlot)->not->toBeNull();
    expect($eventSlot->startAt->format('H:i'))->toBe('18:00');
});

test('slots never overlap and respect travel time', function () {
    $filler = new SlotFiller(new PlanScorer(new TravelEstimator), new TravelEstimator);

    $plan = $filler->fill(defaultConstraints(), fixtureCandidates(), neutralContext(), 50.9442, 6.9329);

    expect(count($plan->slots))->toBeGreaterThanOrEqual(2);

    $previous = null;
    foreach ($plan->slots as $slot) {
        if ($previous !== null) {
            expect($slot->startAt->greaterThanOrEqualTo($previous->endAt))->toBeTrue(
                "slot {$slot->candidate->id} overlaps {$previous->candidate->id}"
            );
        }
        $previous = $slot;
    }
});

test('composition is deterministic for identical input', function () {
    $filler = new SlotFiller(new PlanScorer(new TravelEstimator), new TravelEstimator);

    $a = $filler->fill(defaultConstraints(), fixtureCandidates(), neutralContext(), 50.9442, 6.9329);
    $b = $filler->fill(defaultConstraints(), fixtureCandidates(), neutralContext(), 50.9442, 6.9329);

    expect($a->toArray())->toBe($b->toArray());
});

test('an appointment anchors at its time and gets a travel buffer in', function () {
    $filler = new SlotFiller(new PlanScorer(new TravelEstimator), new TravelEstimator);

    // A nearby park fills the early gap; then a far appointment at 16:00.
    $candidates = [
        makeCandidate(['id' => 'spot:park', 'category' => 'park', 'lat' => 50.9442, 'lng' => 6.9329]),
        makeCandidate([
            'id' => 'appointment:1', 'type' => 'appointment', 'category' => 'appointment',
            'outdoor' => false, 'typicalDurationMin' => 45, 'fixedStart' => saturday('16:00'),
            'swappable' => false, 'lat' => 50.9700, 'lng' => 7.0100,
        ]),
    ];

    $plan = $filler->fill(defaultConstraints(), $candidates, neutralContext(), 50.9442, 6.9329);

    $anchor = collect($plan->slots)->first(fn ($s) => $s->candidate->isAppointment());
    expect($anchor)->not->toBeNull();
    expect($anchor->startAt->format('H:i'))->toBe('16:00');
    expect($anchor->candidate->swappable)->toBeFalse();
    expect($anchor->travelMinFromPrevious)->toBeGreaterThan(0);
});

test('a pinned pick is placed even when a fixed event would fill the window', function () {
    $filler = new SlotFiller(new PlanScorer(new TravelEstimator), new TravelEstimator);
    $context = new ScoringContext(rainExpected: false, preferredAreas: [], pinnedIds: ['spot:pinned']);

    $constraints = new Constraints(windowStart: saturday('14:00'), windowEnd: saturday('16:00'));
    $candidates = [
        makeCandidate(['id' => 'spot:pinned', 'category' => 'cafe', 'outdoor' => false, 'typicalDurationMin' => 60]),
        // A 2-hour event that, anchored first, would otherwise swallow the window.
        makeCandidate(['id' => 'event:big', 'type' => 'event', 'category' => 'community', 'outdoor' => false, 'fixedStart' => saturday('14:00'), 'typicalDurationMin' => 120]),
    ];

    $plan = $filler->fill($constraints, $candidates, $context, 50.9442, 6.9329);

    expect(collect($plan->slots)->map(fn ($s) => $s->candidate->id))->toContain('spot:pinned');
});

// ── Swapper ────────────────────────────────────────────────────────────

test('swap replaces only the target slot and freezes neighbors', function () {
    $travel = new TravelEstimator;
    $filler = new SlotFiller(new PlanScorer($travel), $travel);
    $swapper = new Swapper(new PlanScorer($travel), $travel);

    // A pool larger than the 6-slot cap, so unplaced spares remain for
    // the swapper to pick from (matching real candidate volumes).
    $spares = [];
    foreach (range(1, 6) as $i) {
        $spares[] = makeCandidate([
            'id' => "spot:spare{$i}",
            'category' => 'cafe',
            'outdoor' => false,
            'typicalDurationMin' => 30,
            'costTier' => 'low',
            'lat' => 50.9444 + $i * 0.0003,
            'lng' => 6.9331,
            'veedel' => 'Neustadt-Nord',
        ]);
    }
    $candidates = [...fixtureCandidates(), ...$spares];

    $plan = $filler->fill(defaultConstraints(), $candidates, neutralContext(), 50.9442, 6.9329);
    expect(count($plan->slots))->toBeGreaterThanOrEqual(3);

    // Swap the first non-fixed slot — short spares fit its gap.
    $swapIndex = null;
    foreach ($plan->slots as $i => $slot) {
        if (! $slot->candidate->isFixedTime()) {
            $swapIndex = $i;
            break;
        }
    }
    expect($swapIndex)->not->toBeNull();

    $before = $plan->toArray();
    $after = $swapper->swap($plan, $swapIndex, $candidates, neutralContext(), 50.9442, 6.9329);

    expect($after)->not->toBeNull();
    expect($after->slots[$swapIndex]->candidate->id)
        ->not->toBe($plan->slots[$swapIndex]->candidate->id);

    foreach ($after->slots as $i => $slot) {
        if ($i !== $swapIndex) {
            expect($slot->toArray())->toBe($before['slots'][$i]);
        }
    }
});

test('fixed-time slots refuse to swap', function () {
    $travel = new TravelEstimator;
    $filler = new SlotFiller(new PlanScorer($travel), $travel);
    $swapper = new Swapper(new PlanScorer($travel), $travel);

    $plan = $filler->fill(defaultConstraints(), fixtureCandidates(), neutralContext(), 50.9442, 6.9329);

    $eventIndex = null;
    foreach ($plan->slots as $i => $slot) {
        if ($slot->candidate->isFixedTime()) {
            $eventIndex = $i;
        }
    }
    expect($eventIndex)->not->toBeNull();

    expect($swapper->swap($plan, $eventIndex, fixtureCandidates(), neutralContext(), 50.9442, 6.9329))
        ->toBeNull();
});

test('rejected candidates never come back in the same slot', function () {
    $travel = new TravelEstimator;
    $filler = new SlotFiller(new PlanScorer($travel), $travel);
    $swapper = new Swapper(new PlanScorer($travel), $travel);

    $altA = makeCandidate(['id' => 'spot:altA', 'category' => 'cafe', 'outdoor' => false, 'typicalDurationMin' => 60, 'lat' => 50.9460, 'lng' => 6.9300]);
    $altB = makeCandidate(['id' => 'spot:altB', 'category' => 'library', 'outdoor' => false, 'typicalDurationMin' => 60, 'lat' => 50.9450, 'lng' => 6.9310]);
    $candidates = [...fixtureCandidates(), $altA, $altB];

    $plan = $filler->fill(defaultConstraints(), $candidates, neutralContext(), 50.9442, 6.9329);

    $swapIndex = null;
    foreach ($plan->slots as $i => $slot) {
        if (! $slot->candidate->isFixedTime()) {
            $swapIndex = $i;
        }
    }

    $original = $plan->slots[$swapIndex]->candidate->id;
    $once = $swapper->swap($plan, $swapIndex, $candidates, neutralContext(), 50.9442, 6.9329, [$original]);

    expect($once)->not->toBeNull();
    expect($once->slots[$swapIndex]->candidate->id)->not->toBe($original);

    $twice = $swapper->swap($once, $swapIndex, $candidates, neutralContext(), 50.9442, 6.9329, [
        $original,
        $once->slots[$swapIndex]->candidate->id,
    ]);

    if ($twice !== null) {
        expect($twice->slots[$swapIndex]->candidate->id)->not->toBeIn([
            $original,
            $once->slots[$swapIndex]->candidate->id,
        ]);
    }
});

// ── Graceful degradation: filters never dead-end ───────────────────────

test('a shaped archetype with no matching picks degrades to a real plan, flagged relaxed', function () {
    $constraints = new Constraints(
        windowStart: saturday('14:00'),
        windowEnd: saturday('20:00'),
        categories: ['pitch'],
        archetype: Archetype::MakeADayOfIt, // wants hero/meal/wind-down — never a pitch
    );
    $feasible = [
        makeCandidate(['id' => 'spot:p1', 'name' => 'Bolzplatz Nord', 'category' => 'pitch', 'lat' => 50.944, 'lng' => 6.933]),
        makeCandidate(['id' => 'spot:p2', 'name' => 'Sportplatz Süd', 'category' => 'pitch', 'lat' => 50.946, 'lng' => 6.935]),
    ];

    $plan = filler()->fill($constraints, $feasible, neutralContext(), 50.944, 6.933);

    expect($plan->slots)->not->toBeEmpty();
    expect($plan->relaxed)->toBeTrue();
    expect(array_map(fn ($s) => $s->candidate->category, $plan->slots))->toContain('pitch');
});

test('a shaped archetype that fills normally is not flagged relaxed', function () {
    $constraints = new Constraints(
        windowStart: saturday('14:00'),
        windowEnd: saturday('20:00'),
        archetype: Archetype::MakeADayOfIt,
    );
    $feasible = [
        makeCandidate(['id' => 'spot:m', 'name' => 'Museum', 'category' => 'museum', 'outdoor' => false, 'lat' => 50.944, 'lng' => 6.933]),
        makeCandidate(['id' => 'spot:c', 'name' => 'Café', 'category' => 'cafe', 'outdoor' => false, 'lat' => 50.945, 'lng' => 6.934]),
        makeCandidate(['id' => 'spot:v', 'name' => 'Lookout', 'category' => 'viewpoint', 'lat' => 50.946, 'lng' => 6.935]),
    ];

    $plan = filler()->fill($constraints, $feasible, neutralContext(), 50.944, 6.933);

    expect($plan->slots)->not->toBeEmpty();
    expect($plan->relaxed)->toBeFalse();
});
