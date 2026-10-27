<?php

use App\Home\TodayDiscovery;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Models\UserPlace;
use App\Models\Venue;
use App\Personalisation\TodayTaste;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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

/** Personalisation on under its current wording, and the AI's order for today as TodayRank keeps it. */
function todayOrder(User $user, array $order): void
{
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true]])->assertOk();
    $user->load('userSetting');
    Cache::put(TodayTaste::orderKey($user->id), [...$order, 'at' => discoveryNoon()->toIso8601String()], 3600);
}

/** TodayRanker's id for an event's occurrence. */
function eventKey(Event $event): string
{
    return 'e'.$event->id.'@'.CarbonImmutable::parse($event->starts_at)->getTimestamp();
}

test('the AI\'s order puts its picks first, each why on its own pick only, the rest after as they were', function () {
    $this->travelTo(discoveryNoon());
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);
    $a = discoveryEvent('A at six', '2026-10-26 18:00');
    $b = discoveryEvent('B at seven', '2026-10-26 19:00');
    $c = discoveryEvent('C at eight', '2026-10-26 20:00');
    todayOrder($user, ['events' => [eventKey($c), 'e999@1', eventKey($a)], 'places' => [], 'why' => [eventKey($c) => 'You\'ve been looking at concerts in Ehrenfeld']]);

    $events = collect(app(TodayDiscovery::class)->for($user, discoveryNoon())['events']);

    expect($events->pluck('title')->all())->toBe(['C at eight', 'A at six', 'B at seven'])
        ->and($events->pluck('layout')->all())->toBe(['poster', 'poster', 'compact'])
        ->and($events[0]['why'])->toBe('You\'ve been looking at concerts in Ehrenfeld')
        ->and($events[1])->not->toHaveKey('why')
        ->and($events[2])->not->toHaveKey('why');
});

test('with no order (personalisation off, or not ranked yet), Today keeps its own order', function () {
    $this->travelTo(discoveryNoon());
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);
    discoveryEvent('A at six', '2026-10-26 18:00');
    $b = discoveryEvent('B at seven', '2026-10-26 19:00');
    // Ranked, but personalisation is off: no order.
    Cache::put(TodayTaste::orderKey($user->id), ['events' => [eventKey($b)], 'places' => [], 'why' => [eventKey($b) => 'You\'ve been looking at films'], 'at' => 'now'], 3600);

    $events = collect(app(TodayDiscovery::class)->for($user, discoveryNoon())['events']);

    expect($events->pluck('title')->all())->toBe(['A at six', 'B at seven'])
        ->and($events->every(fn (array $event) => ! isset($event['why'])))->toBeTrue();
});

test('the open places follow the AI\'s order too, and none is dropped', function () {
    $this->travelTo(discoveryNoon());
    $user = User::factory()->create(['veedel' => 'Ehrenfeld']);
    $bakery = Spot::factory()->create([
        'name' => 'Nearest bakery', 'category' => 'bakery',
        'lat' => 50.9504, 'lng' => 6.9113, 'source' => 'osm', 'source_id' => 'node/6',
        'tags' => ['opening_hours' => 'Mo-Sa 06:00-18:30'], 'public_access_allowed' => true,
    ]);
    $cafe = Spot::factory()->create([
        'name' => 'Café a little further', 'category' => 'cafe',
        'lat' => 50.9520, 'lng' => 6.9113, 'source' => 'osm', 'source_id' => 'node/7',
        'tags' => ['opening_hours' => 'Mo-Sa 08:00-18:00'], 'public_access_allowed' => true,
    ]);
    todayOrder($user, ['events' => [], 'places' => ['p'.$cafe->id], 'why' => ['p'.$cafe->id => 'You\'ve been looking at cafés in Ehrenfeld']]);

    $places = collect(app(TodayDiscovery::class)->for($user, discoveryNoon())['around']['places']);

    expect($places->pluck('name')->all())->toBe(['Café a little further', 'Nearest bakery'])
        ->and($places[0]['why'])->toBe('You\'ve been looking at cafés in Ehrenfeld')
        ->and($places[1])->not->toHaveKey('why')
        ->and($bakery->id)->toBe($places[1]['id']);
});

test('a place\'s row is shared for its slot, with each person\'s own distance and saved mark on top', function () {
    $this->travelTo(discoveryNoon());
    $bakery = Spot::factory()->create([
        'name' => 'Shared bakery', 'category' => 'bakery',
        'lat' => 50.9506, 'lng' => 6.9118, 'source' => 'osm', 'source_id' => 'node/8',
        'tags' => ['opening_hours' => 'Mo-Sa 06:00-18:30'], 'public_access_allowed' => true,
    ]);
    $veedel = User::factory()->create(['veedel' => 'Ehrenfeld']);
    $home = User::factory()->create(['veedel' => null]);
    UserPlace::factory()->create(['user_id' => $home->id, 'category' => 'home', 'lat' => 50.9560, 'lng' => 6.9118]);
    $home->spotFeedback()->create(['spot_id' => $bakery->id, 'state' => 'saved']);

    $first = app(TodayDiscovery::class)->for($veedel, discoveryNoon())['around']['places'][0];
    $second = app(TodayDiscovery::class)->for($home, discoveryNoon())['around']['places'][0];
    $slot = discoveryNoon()->getTimestamp();
    $shared = Cache::get("today-discovery:place-row:{$slot}:{$bakery->id}");

    expect($shared)->toBeArray()
        ->and($shared)->not->toHaveKeys(['km', 'saved'])
        ->and($first['km'])->toBeLessThan(0.1)
        ->and($second['km'])->toBeGreaterThan(0.5)
        ->and($first['saved'])->toBeFalse()
        ->and($second['saved'])->toBeTrue();
});
