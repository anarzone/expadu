<?php

use App\Models\Spot;
use App\Places\OpenNearby;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Wiener Platz, Mülheim; Wednesday 30 September 2026, 14:30 in Cologne.
const OPEN_NEARBY_LAT = 50.96103;
const OPEN_NEARBY_LNG = 7.0048;

function openNearbyAt(string $time = '14:30'): CarbonImmutable
{
    return CarbonImmutable::parse("2026-09-30 {$time}", 'Europe/Berlin');
}

function openNearbySpot(string $name, ?string $hours, float $dLat = 0.0004, string $category = 'bakery'): Spot
{
    return Spot::factory()->create([
        'name' => $name,
        'category' => $category,
        'lat' => OPEN_NEARBY_LAT + $dLat,
        'lng' => OPEN_NEARBY_LNG,
        'source' => 'osm',
        'source_id' => 'node/'.fake()->unique()->numberBetween(1, 9_999_999),
        'tags' => $hours === null ? [] : ['opening_hours' => $hours],
        'public_access_allowed' => true,
    ]);
}

test('it lists only places whose recorded hours say open then, nearest first', function () {
    openNearbySpot('Far bakery', 'Mo-Su 06:00-18:30', 0.004);
    openNearbySpot('Near bakery', 'Mo-Su 06:00-18:30', 0.0004);
    openNearbySpot('Closed by then', 'Mo-Su 06:00-13:00', 0.0002);
    openNearbySpot('Hours unknown', null, 0.0001);
    openNearbySpot('Lunch break', 'Mo-Fr 08:00-12:00, 15:00-19:00', 0.0003);
    openNearbySpot('A café', 'We 09:00-19:30', 0.0013, 'cafe');
    openNearbySpot('Not everyday', 'Mo-Su 00:00-24:00', 0.0001, 'museum');

    $rows = app(OpenNearby::class)->around(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt());

    expect($rows->pluck('name')->all())->toBe(['Near bakery', 'A café', 'Far bakery'])
        ->and($rows->first())->toMatchArray(['kind' => 'bakery', 'open_until' => '18:30', 'osm' => true])
        ->and($rows->first()['km'])->toBeLessThan(0.1);
});

test('a place open past midnight counts the night before', function () {
    openNearbySpot('Night café', 'Tu 18:00-02:00', 0.0004, 'cafe');

    expect(app(OpenNearby::class)->around(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt('01:15'))->pluck('open_until')->all())->toBe(['02:00'])
        ->and(app(OpenNearby::class)->around(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt('02:30')))->toBeEmpty();
});

test('🔒 the point and the time are only read: nothing is written, cached or logged', function () {
    openNearbySpot('Near bakery', 'Mo-Su 06:00-18:30');
    Cache::spy();
    Log::spy();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|upsert|merge)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $rows = app(OpenNearby::class)->around(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt());

    expect($rows)->toHaveCount(1)->and($writes)->toBe([]);
    Cache::shouldNotHaveReceived('put');
    Cache::shouldNotHaveReceived('remember');
    Cache::shouldNotHaveReceived('forever');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('warning');
});
