<?php

use App\Composer\Archetype;
use App\Composer\Candidate;
use App\Composer\CategoryHours;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Composer\PlanNarrator;
use App\Composer\PlanScorer;
use App\Composer\PlanSlot;
use App\Composer\ScoringContext;
use App\Composer\TravelEstimator;
use App\Profile\CategoryAffinity;
use Carbon\CarbonImmutable;

/**
 * Time-of-day awareness: assumed category hours, daypart fitness, kid days
 * and the deterministic per-day rotation. Pure fixtures — no DB, no network.
 */
function julyDay(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-07-04 {$time}", 'Europe/Berlin');
}

/**
 * Local candidate factory — parallel test runs load files in separate
 * processes, so helpers from other test files are not available here.
 */
function todCandidate(array $overrides = []): Candidate
{
    $defaults = [
        'id' => 'spot:1',
        'type' => 'spot',
        'name' => 'Spot '.($overrides['id'] ?? 'spot:1'),
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

function todContext(): ScoringContext
{
    return new ScoringContext(rainExpected: false, preferredAreas: ['Neustadt-Nord', 'Ehrenfeld']);
}

function scorer(): PlanScorer
{
    return new PlanScorer(new TravelEstimator);
}

// ── Assumed category hours ──────────────────────────────────────────────

test('a museum with unknown hours is assumed 10:00–18:00', function () {
    [$opens, $closes] = CategoryHours::defaults('museum', julyDay('00:00'));

    expect($opens->format('H:i'))->toBe('10:00');
    expect($closes->format('H:i'))->toBe('18:00');
});

test('a bar with unknown hours crosses midnight into the next day', function () {
    [$opens, $closes] = CategoryHours::defaults('bar', julyDay('00:00'));

    expect($opens->format('H:i'))->toBe('17:00');
    expect($closes->format('d H:i'))->toBe('05 01:00'); // next day, 01:00
});

test('outdoor daylight hours follow the season', function () {
    [, $julyDusk] = CategoryHours::defaults('playground', julyDay('00:00'));
    [, $decemberDusk] = CategoryHours::defaults('playground', CarbonImmutable::parse('2026-12-05', 'Europe/Berlin'));

    expect($julyDusk->format('H:i'))->toBe('21:45');
    expect($decemberDusk->format('H:i'))->toBe('17:30');
});

test('a viewpoint carries no assumed close — a city view works at night', function () {
    expect(CategoryHours::defaults('viewpoint', julyDay('00:00')))->toBe([null, null]);
});

test('a museum with unknown hours never lands in an evening plan', function () {
    // The exact failure seen live: a 19:00–23:00 "tonight" plan proposed
    // museums, because unknown hours meant "always open".
    $evening = new Constraints(windowStart: julyDay('19:00'), windowEnd: julyDay('23:00'));

    [$opensAt, $closesAt] = CategoryHours::defaults('museum', julyDay('00:00'));
    $museum = todCandidate([
        'id' => 'spot:m1', 'category' => 'museum', 'outdoor' => false,
        'opensAt' => $opensAt, 'closesAt' => $closesAt, 'hoursAssumed' => true,
    ]);
    // Real hours always beat the assumption: a late-night exhibition stays.
    $lateMuseum = todCandidate([
        'id' => 'spot:m2', 'category' => 'museum', 'outdoor' => false,
        'opensAt' => julyDay('10:00'), 'closesAt' => julyDay('22:00'),
    ]);

    $surviving = collect((new FeasibilityFilter)->filter($evening, [$museum, $lateMuseum]))->pluck('id');

    expect($surviving)->not->toContain('spot:m1');
    expect($surviving)->toContain('spot:m2');
});

// ── Daypart fitness in the scorer ───────────────────────────────────────

test('the evening favours a bar over a park; the morning does the opposite', function () {
    $bar = todCandidate(['id' => 'spot:b', 'category' => 'bar', 'outdoor' => false]);
    $park = todCandidate(['id' => 'spot:p', 'category' => 'park']);

    $barEvening = scorer()->score($bar, [], julyDay('19:30'), 50.9442, 6.9329, todContext());
    $parkEvening = scorer()->score($park, [], julyDay('19:30'), 50.9442, 6.9329, todContext());
    $barMorning = scorer()->score($bar, [], julyDay('09:30'), 50.9442, 6.9329, todContext());
    $parkMorning = scorer()->score($park, [], julyDay('09:30'), 50.9442, 6.9329, todContext());

    // The daypart term moves each category the right way with the clock —
    // other terms (appeal, affinity) still weigh in on absolute ranking.
    expect($barEvening)->toBeGreaterThan($barMorning);
    expect($parkMorning)->toBeGreaterThan($parkEvening);
});

test('a restaurant peaks at meal times, not mid-afternoon', function () {
    $restaurant = todCandidate(['id' => 'spot:r', 'category' => 'restaurant', 'outdoor' => false]);

    $atLunch = scorer()->score($restaurant, [], julyDay('12:30'), 50.9442, 6.9329, todContext());
    $atDinner = scorer()->score($restaurant, [], julyDay('19:00'), 50.9442, 6.9329, todContext());
    $midAfternoon = scorer()->score($restaurant, [], julyDay('15:30'), 50.9442, 6.9329, todContext());

    expect($atLunch)->toBeGreaterThan($midAfternoon);
    expect($atDinner)->toBeGreaterThan($midAfternoon);
});

// ── "With the kids" is a plan-level override ────────────────────────────

test('with kids, a playground outranks a museum for the same spot on the map', function () {
    $kidContext = new ScoringContext(
        rainExpected: false,
        preferredAreas: ['Neustadt-Nord'],
        companions: 'kids',
        affinity: CategoryAffinity::withKids([]),
    );

    $playground = todCandidate(['id' => 'spot:pg', 'category' => 'playground']);
    $museum = todCandidate(['id' => 'spot:mu', 'category' => 'museum', 'outdoor' => false]);

    $pg = scorer()->score($playground, [], julyDay('14:00'), 50.9442, 6.9329, $kidContext);
    $mu = scorer()->score($museum, [], julyDay('14:00'), 50.9442, 6.9329, $kidContext);

    expect($pg)->toBeGreaterThan($mu);
});

test('a day with kids never routes through a coworking space', function () {
    $constraints = new Constraints(
        windowStart: julyDay('10:00'),
        windowEnd: julyDay('18:00'),
        companions: 'kids',
    );
    $coworking = todCandidate(['id' => 'spot:cw', 'category' => 'coworking', 'outdoor' => false, 'costTier' => 'normal']);

    expect((new FeasibilityFilter)->filter($constraints, [$coworking]))->toBe([]);
});

// ── Deterministic day-to-day rotation ───────────────────────────────────

test('the rotation is stable within a day and shifts across days', function () {
    $candidate = todCandidate(['id' => 'spot:rot']);
    $context = fn (?string $seed) => new ScoringContext(
        rainExpected: false, preferredAreas: [], rotationSeed: $seed,
    );

    $monday = scorer()->score($candidate, [], julyDay('14:00'), 50.9442, 6.9329, $context('7:2026-07-06'));
    $mondayAgain = scorer()->score($candidate, [], julyDay('14:00'), 50.9442, 6.9329, $context('7:2026-07-06'));
    $tuesday = scorer()->score($candidate, [], julyDay('14:00'), 50.9442, 6.9329, $context('7:2026-07-07'));
    $unseeded = scorer()->score($candidate, [], julyDay('14:00'), 50.9442, 6.9329, $context(null));

    expect($monday)->toBe($mondayAgain);       // recompose = same plan
    expect($monday)->not->toBe($tuesday);      // a new day shuffles near-equals
    expect($unseeded)->toBeLessThanOrEqual($monday); // no seed = no bonus
});

// ── Window pacing ───────────────────────────────────────────────────────

test('a balanced day is paced by its window, not crammed to six stops', function () {
    expect(Archetype::Balanced->roles(180)[0]->count)->toBe(2);          // 3h → 2 stops
    expect(Archetype::Balanced->roles(600)[0]->count)->toBe(4);          // 10h → 4 stops
    expect(Archetype::Balanced->roles(null)[0]->count)->toBe(6);         // legacy max
});

// ── Honest narration ────────────────────────────────────────────────────

test('assumed hours are never claimed as "open till"', function () {
    $assumed = todCandidate([
        'id' => 'spot:a1', 'category' => 'museum', 'outdoor' => false,
        'opensAt' => julyDay('10:00'), 'closesAt' => julyDay('18:00'), 'hoursAssumed' => true,
    ]);
    $verified = todCandidate([
        'id' => 'spot:a2', 'category' => 'museum', 'outdoor' => false,
        'opensAt' => julyDay('10:00'), 'closesAt' => julyDay('18:00'),
    ]);

    $why = fn ($candidate) => PlanNarrator::for(
        new PlanSlot($candidate, julyDay('14:00'), julyDay('15:00'), 0),
        null,
        false,
    );

    expect($why($assumed))->not->toContain('open till');
    expect($why($verified))->toContain('open till 18:00');
});
