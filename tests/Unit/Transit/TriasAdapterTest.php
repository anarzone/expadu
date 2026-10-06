<?php

use App\Services\VrsTriasService;
use App\Transit\Dto\GeoPoint;
use App\Transit\TriasAdapter;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
    Mockery::close();
});

function triasDatedJourney(array $overrides = []): array
{
    return array_replace_recursive([
        'total_duration_min' => 30,
        'departure_at' => '2026-10-06T23:50:00+02:00',
        'arrival_at' => '2026-10-07T00:20:00+02:00',
        'departure_time' => '23:50',
        'arrival_time' => '00:20',
        'transfers' => 1,
        'segments' => [
            [
                'type' => 'transit',
                'mode' => 'tram',
                'line' => '5',
                'boarding_stop' => 'Friesenplatz',
                'alighting_stop' => 'Dom/Hbf',
                'departure_at' => '2026-10-06T23:50:00+02:00',
                'arrival_at' => '2026-10-07T00:00:00+02:00',
                'departure_time' => '23:50',
                'arrival_time' => '00:00',
                'duration_min' => 10,
                'coordinates' => [[6.94, 50.94], [6.958, 50.941]],
            ],
            ['type' => 'walk', 'duration_min' => 5],
            [
                'type' => 'transit',
                'mode' => 'rail',
                'line' => 'S12',
                'boarding_stop' => 'Dom/Hbf',
                'alighting_stop' => 'Ehrenfeld',
                'departure_at' => '2026-10-07T00:05:00+02:00',
                'arrival_at' => '2026-10-07T00:20:00+02:00',
                'departure_time' => '00:05',
                'arrival_time' => '00:20',
                'duration_min' => 15,
                'coordinates' => [[6.958, 50.941], [6.92, 50.95]],
            ],
        ],
    ], $overrides);
}

function triasAdapterWithTrip(array $trip): TriasAdapter
{
    $client = Mockery::mock(VrsTriasService::class);
    $client->shouldReceive('planJourney')->with(50.94, 6.94, 50.95, 6.92, 6)
        ->andReturn(['source' => 'trias', 'trips' => [$trip]]);

    return new TriasAdapter($client);
}

test('TRIAS refuses time constraints its client cannot honour', function (?string $time, bool $arriveBy) {
    $adapter = triasAdapterWithTrip(triasDatedJourney());

    expect(fn () => $adapter->plan(
        new GeoPoint(50.94, 6.94),
        new GeoPoint(50.95, 6.92),
        $time === null ? null : CarbonImmutable::parse($time),
        6,
        $arriveBy,
    ))->toThrow(RuntimeException::class, 'TRIAS does not support requested journey times');
})->with([
    'depart at' => ['2026-10-06T23:50:00+02:00', false],
    'arrive by' => ['2026-10-07T00:20:00+02:00', true],
    'arrive now' => [null, true],
]);

test('TRIAS preserves exact dates across midnight and transfers', function () {
    CarbonImmutable::setTestNow('2026-10-06T23:45:00+02:00');

    $journey = triasAdapterWithTrip(triasDatedJourney())
        ->plan(new GeoPoint(50.94, 6.94), new GeoPoint(50.95, 6.92))->journeys[0];

    expect($journey->departAt->toIso8601String())->toBe('2026-10-06T23:50:00+02:00')
        ->and($journey->arriveAt->toIso8601String())->toBe('2026-10-07T00:20:00+02:00')
        ->and($journey->legs[1]->departAt->toIso8601String())->toBe('2026-10-07T00:00:00+02:00')
        ->and($journey->legs[1]->arriveAt->toIso8601String())->toBe('2026-10-07T00:05:00+02:00')
        ->and($journey->legs[2]->departAt->toIso8601String())->toBe('2026-10-07T00:05:00+02:00');
});

test('TRIAS keeps a next-day first departure on its actual date', function () {
    CarbonImmutable::setTestNow('2026-10-05T23:59:00+02:00');

    $journey = triasAdapterWithTrip(triasDatedJourney())
        ->plan(new GeoPoint(50.94, 6.94), new GeoPoint(50.95, 6.92))->journeys[0];

    expect($journey->departAt->toIso8601String())->toBe('2026-10-06T23:50:00+02:00');
});

test('TRIAS respects timezone offsets during the repeated autumn hour', function () {
    $trip = triasDatedJourney();
    $trip['departure_at'] = '2026-10-25T02:50:00+02:00';
    $trip['arrival_at'] = '2026-10-25T02:10:00+01:00';
    $trip['total_duration_min'] = 20;
    $trip['departure_time'] = '02:50';
    $trip['arrival_time'] = '02:10';
    $trip['segments'] = [array_replace($trip['segments'][0], [
        'departure_at' => $trip['departure_at'],
        'arrival_at' => $trip['arrival_at'],
        'departure_time' => '02:50',
        'arrival_time' => '02:10',
        'duration_min' => 20,
    ])];

    $journey = triasAdapterWithTrip($trip)
        ->plan(new GeoPoint(50.94, 6.94), new GeoPoint(50.95, 6.92))->journeys[0];

    expect($journey->departAt->toIso8601String())->toBe('2026-10-25T02:50:00+02:00')
        ->and($journey->arriveAt->toIso8601String())->toBe('2026-10-25T02:10:00+01:00')
        ->and($journey->departAt->diffInMinutes($journey->arriveAt))->toBe(20.0);
});

test('TRIAS refuses ambiguous or invalid dates instead of guessing', function (array $overrides) {
    $adapter = triasAdapterWithTrip(triasDatedJourney($overrides));

    expect(fn () => $adapter->plan(new GeoPoint(50.94, 6.94), new GeoPoint(50.95, 6.92)))
        ->toThrow(RuntimeException::class, 'TRIAS trips could not be mapped');
})->with([
    'legacy clock-only trip' => [['departure_at' => null, 'arrival_at' => null]],
    'date without timezone' => [['departure_at' => '2026-10-06T23:50:00']],
    'invalid calendar date' => [['departure_at' => '2026-02-30T23:50:00+01:00']],
    'reversed arrival' => [['arrival_at' => '2026-10-06T23:40:00+02:00']],
    'legacy clock-only leg' => [['segments' => [2 => ['departure_at' => null, 'arrival_at' => null]]]],
    'transfer departs before previous arrival' => [['segments' => [2 => ['departure_at' => '2026-10-06T23:59:00+02:00']]]],
]);
