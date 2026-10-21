<?php

use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
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

test('listed() keeps every place, with what its hours say then', function () {
    openNearbySpot('Open', 'Mo-Su 06:00-18:30', 0.0001);
    openNearbySpot('Lunch break', 'Mo-Fr 08:00-12:00,15:00-19:00', 0.0002);
    openNearbySpot('Done for the day', 'Mo-Su 06:00-13:00', 0.0003);
    openNearbySpot('Shut on Wednesdays', 'Mo,Tu 08:00-12:00', 0.0004);
    openNearbySpot('Hours unknown', null, 0.0005);

    $rows = app(OpenNearby::class)->listed(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt(), ['bakery'])->keyBy('name');

    expect($rows->keys()->all())->toBe(['Open', 'Lunch break', 'Done for the day', 'Shut on Wednesdays', 'Hours unknown'])
        ->and($rows['Open']['hours'])->toBe(['state' => 'open', 'until' => '18:30'])
        ->and($rows['Lunch break']['hours'])->toBe(['state' => 'closed', 'opens' => '15:00'])
        ->and($rows['Done for the day']['hours'])->toBe(['state' => 'closed', 'today' => true])
        ->and($rows['Shut on Wednesdays']['hours'])->toBe(['state' => 'closed', 'today' => false])
        ->and($rows['Hours unknown']['hours'])->toBe(['state' => 'unknown']);
});

test('given a person, places they marked not interested or been are left out', function () {
    $me = User::factory()->create();
    $kept = openNearbySpot('More like this', 'Mo-Su 06:00-18:30', 0.0001);
    $saved = openNearbySpot('Saved', 'Mo-Su 06:00-18:30', 0.0002);
    $been = openNearbySpot('Been', 'Mo-Su 06:00-18:30', 0.0003);
    $no = openNearbySpot('Not interested', 'Mo-Su 06:00-18:30', 0.0004);
    SpotFeedback::factory()->for($me)->for($kept)->create(['state' => 'more_like_this']);
    SpotFeedback::factory()->for($me)->for($saved)->create();
    SpotFeedback::factory()->for($me)->for($been)->been()->create();
    SpotFeedback::factory()->for($me)->for($no)->notInterested()->create();
    // Someone else's feedback changes nothing for me.
    SpotFeedback::factory()->for($kept)->notInterested()->create();

    $open = app(OpenNearby::class);
    $around = $open->around(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt(), for: $me)->pluck('name')->all();
    $listed = $open->listed(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt(), ['bakery'], for: $me)->pluck('name')->all();

    expect($around)->toBe(['More like this', 'Saved'])
        ->and($listed)->toBe(['More like this', 'Saved'])
        ->and($open->listed(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt(), ['bakery'])->pluck('name')->all())->toHaveCount(4);
});

test('setting aside a place by another record of it leaves the place out too', function () {
    $me = User::factory()->create();
    $place = openNearbySpot('Bakery', 'Mo-Su 06:00-18:30');
    $alias = openNearbySpot('Bakery (older record)', 'Mo-Su 06:00-18:30', 0.0001);
    $alias->forceFill(['canonical_spot_id' => $place->id])->save();
    SpotFeedback::factory()->for($me)->for($alias)->notInterested()->create();

    $open = app(OpenNearby::class);

    expect($open->around(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt(), for: $me)->pluck('id')->all())->not->toContain($place->id)
        ->and($open->listed(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt(), ['bakery'], for: $me)->pluck('id')->all())->not->toContain($place->id)
        ->and($open->listed(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt(), ['bakery'])->pluck('id')->all())->toContain($place->id);
});

test('🔒 the point and the time are only read: nothing is written, cached or logged', function () {
    openNearbySpot('Near bakery', 'Mo-Su 06:00-18:30');
    $me = User::factory()->create();
    Cache::spy();
    Log::spy();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|upsert|merge)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $rows = app(OpenNearby::class)->around(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt());
    $listed = app(OpenNearby::class)->listed(OPEN_NEARBY_LAT, OPEN_NEARBY_LNG, openNearbyAt(), ['bakery'], for: $me);

    expect($rows)->toHaveCount(1)->and($listed)->toHaveCount(1)->and($writes)->toBe([]);
    Cache::shouldNotHaveReceived('put');
    Cache::shouldNotHaveReceived('remember');
    Cache::shouldNotHaveReceived('forever');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('warning');
});
