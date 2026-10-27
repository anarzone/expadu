<?php

use App\Home\TodayDiscovery;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Models\UserPlace;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Monday 26 October 2026 at noon in Cologne, nothing planned.
function discoveryNoon(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-26 12:00', 'Europe/Berlin');
}

function discoveryEvent(string $title, string $start, ?Venue $venue = null, ?string $end = null): Event
{
    return Event::factory()->create([
        'title' => $title,
        'starts_at' => CarbonImmutable::parse($start, 'Europe/Berlin')->utc(),
        'ends_at' => $end === null ? null : CarbonImmutable::parse($end, 'Europe/Berlin')->utc(),
        'venue_id' => $venue?->id,
    ]);
}

beforeEach(function () {
    DB::table('veedels')->insert([
        ['name' => 'Ehrenfeld', 'bezirk' => 'Ehrenfeld', 'centroid_lat' => 50.9503304, 'centroid_lng' => 6.9112611],
    ]);
});

test('today\'s events come first, near the person\'s Veedel before the rest', function () {
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);
    $near = Venue::create(['name' => 'Club Bahnhof Ehrenfeld', 'lat' => 50.9510, 'lng' => 6.9170, 'veedel' => 'Ehrenfeld']);
    $far = Venue::create(['name' => 'Porz hall', 'lat' => 50.8800, 'lng' => 7.0600, 'veedel' => 'Porz']);
    discoveryEvent('Far and early', '2026-10-26 18:00', $far);
    discoveryEvent('Near and later', '2026-10-26 20:00', $near);
    discoveryEvent('Tomorrow near', '2026-10-27 19:00', $near);

    $result = app(TodayDiscovery::class)->for($user, discoveryNoon());

    expect(collect($result['events'])->pluck('title')->all())->toBe(['Near and later', 'Far and early'])
        ->and($result['part'])->toBe('afternoon');
});

test('tomorrow shows only once nothing is left today, and an event still on counts as left', function () {
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);
    discoveryEvent('Tomorrow', '2026-10-27 19:00');
    discoveryEvent('Exhibition on until six', '2026-10-26 10:00', end: '2026-10-26 18:00');

    expect(collect(app(TodayDiscovery::class)->for($user, discoveryNoon())['events'])->pluck('title')->all())
        ->toBe(['Exhibition on until six']);

    $evening = CarbonImmutable::parse('2026-10-26 19:00', 'Europe/Berlin');
    $result = app(TodayDiscovery::class)->for($user, $evening);

    expect(collect($result['events'])->pluck('title')->all())->toBe(['Tomorrow'])
        ->and($result['events'][0]['day'])->toBe('tomorrow')
        ->and($result['part'])->toBe('tomorrow');
});

test('with no plan to go by, places open now near the Veedel, named for it', function () {
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);
    Spot::factory()->create([
        'name' => 'Bäckerei an der Venloer', 'category' => 'bakery',
        'lat' => 50.9506, 'lng' => 6.9118, 'source' => 'osm', 'source_id' => 'node/1',
        'tags' => ['opening_hours' => 'Mo-Sa 06:00-18:30'], 'public_access_allowed' => true,
    ]);
    Spot::factory()->create([
        'name' => 'Closed café', 'category' => 'cafe',
        'lat' => 50.9504, 'lng' => 6.9114, 'source' => 'osm', 'source_id' => 'node/2',
        'tags' => ['opening_hours' => 'Mo-Sa 06:00-09:00'], 'public_access_allowed' => true,
    ]);

    $around = app(TodayDiscovery::class)->for($user, discoveryNoon())['around'];

    expect($around['anchor'])->toBeNull()
        ->and($around['near'])->toBe(['name' => 'Ehrenfeld', 'key' => 'Ehrenfeld'])
        ->and($around['at'])->toBe('12:00')
        ->and(collect($around['places'])->pluck('name')->all())->toBe(['Bäckerei an der Venloer'])
        ->and($around['places'][0])->toMatchArray(['icon' => 'bakery', 'saved' => false, 'hours' => ['state' => 'open', 'until' => '18:30']]);
});

test('without a Veedel it falls back to Home, and without either it shows nothing', function () {
    $home = User::factory()->create(['veedel' => null]);
    UserPlace::factory()->create(['user_id' => $home->id, 'category' => 'home', 'lat' => 50.9506, 'lng' => 6.9118]);
    Spot::factory()->create([
        'name' => 'Corner pharmacy', 'category' => 'pharmacy',
        'lat' => 50.9508, 'lng' => 6.9120, 'source' => 'osm', 'source_id' => 'node/3',
        'tags' => ['opening_hours' => 'Mo-Fr 08:00-18:00'], 'public_access_allowed' => true,
    ]);

    $around = app(TodayDiscovery::class)->for($home, discoveryNoon())['around'];

    expect($around['near'])->toBe(['name' => 'Home', 'key' => 'home'])
        ->and(collect($around['places'])->pluck('name')->all())->toBe(['Corner pharmacy'])
        // Home's position stays on the server.
        ->and($around)->not->toHaveKey('lat');

    $nobody = User::factory()->create(['veedel' => null]);

    expect(app(TodayDiscovery::class)->for($nobody, discoveryNoon())['around'])->toBeNull();
});

test('with nothing open within 1 km of the Veedel, it looks at 2 km before giving up', function () {
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);
    Spot::factory()->create([
        'name' => 'Closed at noon', 'category' => 'bakery',
        'lat' => 50.9506, 'lng' => 6.9118, 'source' => 'osm', 'source_id' => 'node/4',
        'tags' => ['opening_hours' => 'Mo-Sa 06:00-10:00'], 'public_access_allowed' => true,
    ]);
    // About 1.5 km north of Ehrenfeld's centre.
    Spot::factory()->create([
        'name' => 'Open a bit further', 'category' => 'bakery',
        'lat' => 50.9638, 'lng' => 6.9113, 'source' => 'osm', 'source_id' => 'node/5',
        'tags' => ['opening_hours' => 'Mo-Sa 06:00-18:00'], 'public_access_allowed' => true,
    ]);

    $around = app(TodayDiscovery::class)->for($user, discoveryNoon())['around'];

    expect($around['near']['name'])->toBe('Ehrenfeld')
        ->and(collect($around['places'])->pluck('name')->all())->toBe(['Open a bit further'])
        ->and($around['places'][0]['km'])->toBeGreaterThan(1.0)->toBeLessThan(2.0);
});
