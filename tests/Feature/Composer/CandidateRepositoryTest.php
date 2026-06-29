<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Models\Spot;
use Carbon\CarbonImmutable;

function spotWithHours(array $overrides): Spot
{
    return Spot::factory()->create(array_merge([
        'lat' => 50.94,
        'lng' => 6.95,
        'veedel' => 'Altstadt',
    ], $overrides));
}

// 2026-06-15 is a Monday.
function mondayWindow(): Constraints
{
    return new Constraints(
        windowStart: CarbonImmutable::parse('2026-06-15 10:00', 'Europe/Berlin'),
        windowEnd: CarbonImmutable::parse('2026-06-15 18:00', 'Europe/Berlin'),
    );
}

test('a venue closed on the plan weekday is dropped by feasibility', function () {
    spotWithHours([
        'name' => 'Closed Mondays',
        'category' => 'museum',
        'opening_hours' => ['mon' => [], 'tue' => [['10:00', '18:00']]],
    ]);

    $constraints = mondayWindow();
    $candidates = app(CandidateRepository::class)->candidatesFor($constraints);
    $feasible = app(FeasibilityFilter::class)->filter($constraints, $candidates);

    expect(collect($candidates)->firstWhere('name', 'Closed Mondays')->closedToday)->toBeTrue();
    expect(collect($feasible)->pluck('name'))->not->toContain('Closed Mondays');
});

test('an open venue keeps real hours and survives feasibility', function () {
    spotWithHours([
        'name' => 'Open Museum',
        'category' => 'museum',
        'opening_hours' => ['mon' => [['09:00', '18:00']]],
    ]);

    $constraints = mondayWindow();
    $candidates = app(CandidateRepository::class)->candidatesFor($constraints);
    $cand = collect($candidates)->firstWhere('name', 'Open Museum');

    expect($cand->closedToday)->toBeFalse();
    expect($cand->closesAt?->format('H:i'))->toBe('18:00');
    expect(collect(app(FeasibilityFilter::class)->filter($constraints, $candidates))->pluck('name'))
        ->toContain('Open Museum');
});

test('a raw-string opening_hours does not break the candidate pool', function () {
    // Scraped restaurants store a raw OSM string ("Mo-Fr 09:00-18:00"), which
    // the array cast returns as a string rather than the importer's structured
    // array. The pipeline must tolerate it (assume open), not fatal the plan —
    // this is the prod-only crash that broke plan mode.
    spotWithHours([
        'name' => 'Scraped Bistro',
        'category' => 'restaurant',
        'opening_hours' => 'Mo-Fr 09:00-18:00; Sa 10:00-14:00',
    ]);

    $candidates = app(CandidateRepository::class)->candidatesFor(mondayWindow());
    $cand = collect($candidates)->firstWhere('name', 'Scraped Bistro');

    expect($cand)->not->toBeNull();
    expect($cand->opensAt)->toBeNull();
    expect($cand->closesAt)->toBeNull();
    expect($cand->closedToday)->toBeFalse();
});

test('the pool is the nearest spots to the origin, not arbitrary citywide rows', function () {
    // 12 pitches clustered far north, plus one right by the origin. With a
    // 12-per-category cap, an origin-blind query (lowest id) would fetch the
    // far cluster and silently drop the local pitch — the bug that made the
    // composer ignore the spots actually near the user.
    foreach (range(1, 12) as $i) {
        spotWithHours(['name' => "Far Pitch {$i}", 'category' => 'pitch', 'lat' => 51.05, 'lng' => 6.95]);
    }
    spotWithHours(['name' => 'Near Pitch', 'category' => 'pitch', 'lat' => 50.94, 'lng' => 6.95]);

    // Origin sits on the local pitch.
    $candidates = app(CandidateRepository::class)->candidatesFor(mondayWindow(), 50.94, 6.95);
    $pitchNames = collect($candidates)->where('category', 'pitch')->pluck('name');

    expect($pitchNames)->toContain('Near Pitch')
        ->and($pitchNames)->not->toContain('Far Pitch 12'); // the farthest fell outside the cap
});

test('public outdoor facilities count as free; paid venues do not', function () {
    spotWithHours(['name' => 'Boule', 'category' => 'boules']);
    spotWithHours(['name' => 'Tisch', 'category' => 'table_tennis']);
    spotWithHours(['name' => 'Tennis', 'category' => 'tennis']);
    spotWithHours(['name' => 'Picknick', 'category' => 'picnic']);
    spotWithHours(['name' => 'Späti Bar', 'category' => 'bar']);

    $c = app(CandidateRepository::class)->candidatesFor(mondayWindow());

    foreach (['Boule', 'Tisch', 'Tennis', 'Picknick'] as $free) {
        expect(collect($c)->firstWhere('name', $free)->costTier)->toBe('free');
    }
    expect(collect($c)->firstWhere('name', 'Späti Bar')->costTier)->toBe('normal');
});

test('wikidata or wikipedia tags mark a spot as a landmark', function () {
    spotWithHours(['name' => 'Famous Park', 'category' => 'park', 'tags' => ['wikidata' => 'Q123']]);
    spotWithHours(['name' => 'Plain Park', 'category' => 'park', 'tags' => ['wheelchair' => 'yes']]);

    $candidates = app(CandidateRepository::class)->candidatesFor(mondayWindow());

    expect(collect($candidates)->firstWhere('name', 'Famous Park')->isLandmark)->toBeTrue();
    expect(collect($candidates)->firstWhere('name', 'Plain Park')->isLandmark)->toBeFalse();
});
