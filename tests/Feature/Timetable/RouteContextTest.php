<?php

use App\Services\GtfsDepartureService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();

    DB::table('gtfs_stops')->insert([
        ['stop_id' => 'NM1', 'stop_name' => 'Neumarkt', 'stop_lat' => 50.9364, 'stop_lng' => 6.9470, 'location_type' => 0],
        // City-prefixed as in the real VRS feed — routeContext strips it.
        ['stop_id' => 'HM1', 'stop_name' => 'Köln Heumarkt', 'stop_lat' => 50.9358, 'stop_lng' => 6.9601, 'location_type' => 0],
        ['stop_id' => 'DM1', 'stop_name' => 'Deutz/Messe', 'stop_lat' => 50.9410, 'stop_lng' => 6.9750, 'location_type' => 0],
        ['stop_id' => 'RP1', 'stop_name' => 'Rudolfplatz', 'stop_lat' => 50.9360, 'stop_lng' => 6.9340, 'location_type' => 0],
    ]);
    DB::table('gtfs_routes')->insert([
        'route_id' => 'R1', 'route_short_name' => '1', 'route_long_name' => 'Stadtbahn 1', 'route_type' => 0, 'route_color' => 'E2001A',
    ]);
    DB::table('gtfs_trips')->insert([
        // Eastbound toward Bensberg — GTFS headsign carries the city prefix.
        ['trip_id' => 'T-east', 'route_id' => 'R1', 'service_id' => 'S', 'trip_headsign' => 'Köln Bensberg', 'direction_id' => 0],
        // Westbound toward Weiden.
        ['trip_id' => 'T-west', 'route_id' => 'R1', 'service_id' => 'S', 'trip_headsign' => 'Weiden West', 'direction_id' => 1],
    ]);
    DB::table('gtfs_stop_times')->insert([
        ['trip_id' => 'T-east', 'stop_id' => 'NM1', 'arrival_time' => '10:00:00', 'departure_time' => '10:00:00', 'stop_sequence' => 1],
        ['trip_id' => 'T-east', 'stop_id' => 'HM1', 'arrival_time' => '10:02:00', 'departure_time' => '10:02:00', 'stop_sequence' => 2],
        ['trip_id' => 'T-east', 'stop_id' => 'DM1', 'arrival_time' => '10:05:00', 'departure_time' => '10:05:00', 'stop_sequence' => 3],
        ['trip_id' => 'T-west', 'stop_id' => 'NM1', 'arrival_time' => '10:01:00', 'departure_time' => '10:01:00', 'stop_sequence' => 5],
        ['trip_id' => 'T-west', 'stop_id' => 'RP1', 'arrival_time' => '10:03:00', 'departure_time' => '10:03:00', 'stop_sequence' => 6],
    ]);
});

test('routeContext resolves the travel direction and the next stops ridden', function () {
    $context = app(GtfsDepartureService::class)->routeContext('Neumarkt', '1', 'Köln Bensberg');

    expect($context['direction'])->toBe(0);
    expect($context['via'])->toBe(['Heumarkt', 'Deutz/Messe']);
});

test('routeContext matches a live destination that GTFS spells with a prefix', function () {
    // TRIAS says "Bensberg"; the GTFS headsign is "Köln Bensberg".
    $context = app(GtfsDepartureService::class)->routeContext('Neumarkt', '1', 'Bensberg');

    expect($context['direction'])->toBe(0);
    expect($context['via'])->toBe(['Heumarkt', 'Deutz/Messe']);
});

test('routeContext separates the two travel directions of a line', function () {
    $west = app(GtfsDepartureService::class)->routeContext('Neumarkt', '1', 'Weiden West');

    expect($west['direction'])->toBe(1);
    expect($west['via'])->toBe(['Rudolfplatz']);
});

test('routeContext degrades to nulls when GTFS cannot match', function () {
    $service = app(GtfsDepartureService::class);

    expect($service->routeContext('Neumarkt', '999', 'Nowhere'))
        ->toBe(['direction' => null, 'via' => []]);
    expect($service->routeContext('Unknown Stop', '1', 'Bensberg'))
        ->toBe(['direction' => null, 'via' => []]);
});
