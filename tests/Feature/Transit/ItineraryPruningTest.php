<?php

use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config(['services.motis.url' => 'http://motis.test']);
});

/**
 * A plan payload whose itineraries vary only in duration + departure — the
 * knobs pruneAbsurd() reads. Times are minutes relative to a fixed T0.
 *
 * @param  array<int, array{dur: int, dep: int}>  $shapes
 */
function planFixtureWith(array $shapes): array
{
    $base = json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true);
    $template = $base['itineraries'][0];
    $t0 = CarbonImmutable::parse('2026-07-01T21:00:00Z');

    $base['itineraries'] = array_map(function (array $shape) use ($template, $t0) {
        $itinerary = $template;
        $itinerary['duration'] = $shape['dur'] * 60;
        $itinerary['startTime'] = $t0->addMinutes($shape['dep'])->toIso8601ZuluString();
        $itinerary['endTime'] = $t0->addMinutes($shape['dep'] + $shape['dur'])->toIso8601ZuluString();

        return $itinerary;
    }, $shapes);

    // No direct journeys in these tests — transit pruning only.
    $base['direct'] = [];

    return $base;
}

test('a night-gap blow-up itinerary is pruned', function () {
    // 67min best, a later 84min sibling, and MOTIS\'s pareto-kept 295min
    // "ride into the night gap" — the last one must not reach the UI.
    Http::fake(['motis.test/api/v1/plan*' => Http::response(planFixtureWith([
        ['dur' => 67, 'dep' => 0],
        ['dur' => 84, 'dep' => 30],
        ['dur' => 295, 'dep' => 38],
    ]))]);

    $journeys = app(RouteService::class)
        ->plan(new GeoPoint(51.024, 6.953), new GeoPoint(50.953, 6.893))
        ->journeys;

    $durations = collect($journeys)->map(fn ($j) => $j->durationMin)->all();

    expect($durations)->toContain(67, 84)
        ->and($durations)->not->toContain(295);
});

test('much-later departures are windowed out when enough near options exist', function () {
    // Three options inside 90 minutes + tomorrow\'s first train (5.5h later,
    // itself short) — the far-future one is noise and gets dropped.
    Http::fake(['motis.test/api/v1/plan*' => Http::response(planFixtureWith([
        ['dur' => 67, 'dep' => 0],
        ['dur' => 70, 'dep' => 20],
        ['dur' => 84, 'dep' => 40],
        ['dur' => 60, 'dep' => 330],
    ]))]);

    $journeys = app(RouteService::class)
        ->plan(new GeoPoint(51.024, 6.953), new GeoPoint(50.953, 6.893))
        ->journeys;

    expect($journeys)->toHaveCount(3)
        ->and(collect($journeys)->map(fn ($j) => $j->durationMin)->all())
        ->not->toContain(60);
});

test('a genuinely sparse night still offers the next real service', function () {
    // Only two sane options and the second is past the window (you missed
    // the last train — next is tomorrow). Top-up keeps it visible.
    Http::fake(['motis.test/api/v1/plan*' => Http::response(planFixtureWith([
        ['dur' => 67, 'dep' => 0],
        ['dur' => 60, 'dep' => 330],
    ]))]);

    $journeys = app(RouteService::class)
        ->plan(new GeoPoint(51.024, 6.953), new GeoPoint(50.953, 6.893))
        ->journeys;

    expect($journeys)->toHaveCount(2);
});
