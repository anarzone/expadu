<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Enums\SpotCategory;
use App\Models\Event;
use App\Models\Spot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['events.geocoding.expected_polygon_count' => 1]);
    DB::table('veedels')->insert([
        'name' => 'Composer Test Boundary',
        'bezirk' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement(<<<'SQL'
        UPDATE veedels
        SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326))
        WHERE name = 'Composer Test Boundary'
        SQL);
});

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

    // Unparseable hours fall back to the category's typical hours, marked
    // assumed — never claimed to the user, never a fatal.
    expect($cand)->not->toBeNull();
    expect($cand->hoursAssumed)->toBeTrue();
    expect($cand->opensAt?->format('H:i'))->toBe('11:30');
    expect($cand->closesAt?->format('H:i'))->toBe('23:00');
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

test('a curated event survives a spot pool that overflows the candidate cap', function () {
    // The blocker: spots were merged BEFORE events and the whole list sliced to
    // MAX_CANDIDATES. At prod volume the nearest-per-category spots overflow that
    // cap on their own, so every event was truncated away — curated events could
    // never reach a plan. Overflow the pool here (18 categories × 12 nearest =
    // 216 > 200) and prove the event still makes it in.
    $rows = [];
    foreach (SpotCategory::placesFines() as $category) {
        foreach (range(1, 12) as $i) {
            $rows[] = [
                'name' => "{$category} {$i}",
                'category' => $category,
                'lat' => 50.94,
                'lng' => 6.95,
            ];
        }
    }
    Spot::query()->insert($rows);
    expect(count($rows))->toBeGreaterThan(200); // the pool overflows the cap

    $event = Event::factory()->create([
        'title' => 'Curated Rooftop Market',
        'category' => 'market',
        'is_curated' => true,
        'relevance' => 0.9,
        'quality_score' => 0.9,
        'starts_at' => CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'),
    ]);
    DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [6.95, 50.94, $event->id],
    );

    $candidates = app(CandidateRepository::class)->candidatesFor(mondayWindow(), 50.94, 6.95);

    // The event is in the pool despite the spot overflow, the cap is still
    // respected, and spots weren't crowded out either.
    expect(collect($candidates)->firstWhere('id', "event:{$event->id}"))->not->toBeNull();
    expect(count($candidates))->toBeLessThanOrEqual(200);
    expect(collect($candidates)->where('type', 'spot'))->not->toBeEmpty();
});

test('only quality-gated events reach the composer; a curated outdoor event is a landmark', function () {
    $window = new Constraints(
        windowStart: CarbonImmutable::parse('2026-06-15 10:00', 'Europe/Berlin'),
        windowEnd: CarbonImmutable::parse('2026-06-15 22:00', 'Europe/Berlin'),
    );
    $at = fn (Event $e, float $lat, float $lng) => DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $e->id],
    );

    // A junk event below every quality bar must not reach a plan.
    $junk = Event::factory()->create([
        'title' => 'Junk Event', 'category' => 'other', 'is_curated' => false,
        'relevance' => 0.1, 'quality_score' => 0.1,
        'starts_at' => CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'),
    ]);
    $at($junk, 50.94, 6.95);

    // A curated open-air market — quality-gated in, marked outdoor + hero.
    $market = Event::factory()->create([
        'title' => 'Ehrenfeld Street Market', 'category' => 'market', 'is_curated' => true,
        'relevance' => 0.9, 'quality_score' => 0.9,
        'starts_at' => CarbonImmutable::parse('2026-06-15 15:00', 'Europe/Berlin'),
    ]);
    $at($market, 50.95, 6.92);

    $candidates = collect(app(CandidateRepository::class)->candidatesFor($window));

    expect($candidates->firstWhere('name', 'Junk Event'))->toBeNull();
    $m = $candidates->firstWhere('name', 'Ehrenfeld Street Market');
    expect($m)->not->toBeNull();
    expect($m->outdoor)->toBeTrue();
    expect($m->isLandmark)->toBeTrue();
});

test('a recurring event occurrence inside the plan window reaches the composer', function () {
    $event = Event::factory()->create([
        'title' => 'Weekly Community Dinner',
        'category' => 'food',
        'is_curated' => true,
        'relevance' => 0.9,
        'quality_score' => 0.9,
        'starts_at' => CarbonImmutable::parse('2026-06-01 18:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-06-01 20:00', 'Europe/Berlin'),
        'recurrence' => 'FREQ=WEEKLY;BYDAY=MO',
        'recurrence_until' => CarbonImmutable::parse('2026-07-01 23:59', 'Europe/Berlin'),
    ]);
    DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [6.95, 50.94, $event->id],
    );

    $candidate = collect(app(CandidateRepository::class)->candidatesFor(mondayWindow(), 50.94, 6.95))
        ->first(fn ($candidate) => str_starts_with($candidate->id, "event:{$event->id}:"));

    expect($candidate)->not->toBeNull()
        ->and($candidate->fixedStart?->format('Y-m-d H:i'))->toBe('2026-06-15 18:00')
        ->and($candidate->typicalDurationMin)->toBe(120);
});

test('events outside the Cologne service area never reach the composer pool', function () {
    DB::table('veedels')->insert([
        'name' => 'Composer Test Köln',
        'bezirk' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement(<<<'SQL'
        UPDATE veedels
        SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326))
        WHERE name = 'Composer Test Köln'
        SQL);

    $cologne = Event::factory()->create([
        'title' => 'Cologne Community Night',
        'is_curated' => true,
        'relevance' => 0.9,
        'quality_score' => 0.9,
        'starts_at' => CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'),
    ]);
    $berlin = Event::factory()->create([
        'title' => 'Mis-geocoded Open Air Cinema',
        'is_curated' => true,
        'relevance' => 0.9,
        'quality_score' => 0.9,
        'starts_at' => CarbonImmutable::parse('2026-06-15 13:00', 'Europe/Berlin'),
    ]);

    DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [6.95, 50.94, $cologne->id],
    );
    DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [13.2015024, 52.5364431, $berlin->id],
    );

    $eventIds = collect(app(CandidateRepository::class)->candidatesFor(mondayWindow(), 50.995, 6.955))
        ->where('type', 'event')
        ->pluck('id');

    expect($eventIds)->toContain("event:{$cologne->id}")
        ->not->toContain("event:{$berlin->id}");
});

test('a nearby coordinate outside the official Cologne polygons is excluded', function () {
    DB::table('veedels')->insert([
        'name' => 'Composer Boundary Köln',
        'bezirk' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement(<<<'SQL'
        UPDATE veedels
        SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326))
        WHERE name = 'Composer Boundary Köln'
        SQL);

    $outside = Event::factory()->create([
        'title' => 'Nearby Hürth Event',
        'is_curated' => true,
        'relevance' => 0.9,
        'quality_score' => 0.9,
        'starts_at' => CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'),
    ]);
    DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [6.90, 50.88, $outside->id],
    );

    expect(collect(app(CandidateRepository::class)->candidatesFor(mondayWindow(), 50.94, 6.95))->pluck('id'))
        ->not->toContain("event:{$outside->id}");
});

test('recurring occurrences have stable unique candidate ids', function () {
    DB::table('veedels')->insert([
        'name' => 'Composer Recurrence Köln',
        'bezirk' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::statement(<<<'SQL'
        UPDATE veedels
        SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326))
        WHERE name = 'Composer Recurrence Köln'
        SQL);
    $event = Event::factory()->create([
        'title' => 'Daily Festival Programme',
        'is_curated' => true,
        'relevance' => 0.9,
        'quality_score' => 0.9,
        'starts_at' => CarbonImmutable::parse('2026-06-14 14:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-06-14 15:00', 'Europe/Berlin'),
        'recurrence' => 'FREQ=DAILY',
        'recurrence_until' => CarbonImmutable::parse('2026-06-16 23:59', 'Europe/Berlin'),
    ]);
    DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [6.95, 50.94, $event->id],
    );
    $window = new Constraints(
        windowStart: CarbonImmutable::parse('2026-06-15 00:00', 'Europe/Berlin'),
        windowEnd: CarbonImmutable::parse('2026-06-16 23:59', 'Europe/Berlin'),
    );

    $occurrences = collect(app(CandidateRepository::class)->candidatesFor($window, 50.94, 6.95))
        ->where('type', 'event')
        ->where(fn ($candidate) => str_starts_with($candidate->id, "event:{$event->id}:"));

    expect($occurrences)->toHaveCount(2)
        ->and($occurrences->pluck('id')->unique())->toHaveCount(2);
});
