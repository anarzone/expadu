<?php

use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use App\Transit\JourneyRanker;
use Carbon\CarbonImmutable;

/** A way leaving $leave minutes from now: the walk first, then the rides one after the other. */
function way(int $leave, int $minutes, array $lines = [], int $walking = 0, ?int $walkMetres = null, bool $cancelled = false, string $mode = 'walk'): Journey
{
    $now = CarbonImmutable::parse('2026-11-03 18:40', 'Europe/Berlin');
    $place = new Place('Stop', new GeoPoint(50.9, 6.9));
    $at = $now->addMinutes($leave);
    $legs = [];
    if ($walking > 0) {
        $legs[] = new Leg($mode, $place, $place, $at, $at->addMinutes($walking), $walking, distanceM: $walkMetres);
        $at = $at->addMinutes($walking);
    }
    $each = $lines === [] ? 0 : (int) floor(($minutes - $walking) / count($lines));
    foreach ($lines as $line) {
        $legs[] = new Leg('tram', $place, $place, $at, $at->addMinutes($each), $each, lineName: $line, cancelled: $cancelled);
        $at = $at->addMinutes($each);
    }

    return new Journey($legs, $now->addMinutes($leave), $now->addMinutes($leave + $minutes), $minutes, max(0, count($lines) - 1));
}

function order(array $journeys, bool $arriveBy = false): array
{
    return array_map(
        fn (Journey $j): string => $j->mode() === 'transit' ? implode('+', $j->lines()).'@'.$j->departAt->format('H:i') : $j->mode(),
        (new JourneyRanker)->rank($journeys, $arriveBy),
    );
}

it('never puts a bike first, however quick', function () {
    expect(order([way(0, 12, walking: 12, mode: 'bike'), way(5, 25, ['1'], 5)]))
        ->toBe(['1@18:45', 'bike']);
});

it('leads with a short walk that is about as quick as riding', function () {
    expect(order([way(3, 8, ['15'], 3), way(0, 12, walking: 12)]))->toBe(['walk', '15@18:43']);
});

it('keeps a far walk last unless riding takes even longer', function () {
    expect(order([way(5, 25, ['1'], 5), way(10, 25, ['1'], 5), way(0, 50, walking: 50)]))
        ->toBe(['1@18:45', '1@18:50', 'walk']);
    expect(order([way(5, 70, ['1', '9'], 5), way(15, 70, ['1', '9'], 5), way(0, 50, walking: 50)]))
        ->toBe(['1+9@18:45', 'walk', '1+9@18:55']);
});

it('offers different ways before the next run of one already listed', function () {
    expect(order([way(0, 20, ['18'], 2), way(10, 20, ['18'], 2), way(4, 24, ['5', '12'], 3)]))
        ->toBe(['18@18:40', '5+12@18:44', '18@18:50']);
});

it('prefers less walking over a couple of minutes', function () {
    expect(order([way(0, 28, ['S12'], 28), way(0, 30, ['1'], 6)]))->toBe(['1@18:40', 'S12@18:40']);
});

it('does not count the router\'s wait at a stop as walking', function () {
    expect(order([way(0, 30, ['1'], 10, walkMetres: 0), way(0, 29, ['7'], 10, walkMetres: 700)]))
        ->toBe(['1@18:40', '7@18:40']);
});

it('goes by how long a way takes when asked to be there by a time', function () {
    expect(order([way(0, 45, ['3'], 5), way(20, 25, ['4'], 5)], arriveBy: true))->toBe(['4@19:00', '3@18:40']);
});

it('puts a cancelled way last and lets its line count again', function () {
    expect(order([way(0, 20, ['18'], 2, cancelled: true), way(10, 20, ['18'], 2), way(4, 30, ['5'], 3)]))
        ->toBe(['18@18:50', '5@18:44', '18@18:40']);
});

it('does not take a way hours later for an alternative', function () {
    expect(order([way(0, 40, ['7'], 5), way(30, 40, ['7'], 5), way(200, 46, ['15', 'S12'], 8)]))
        ->toBe(['7@18:40', '7@19:10', '15+S12@22:00']);
});
