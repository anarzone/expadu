<?php

use App\Models\Event;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\GeocodingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/*
 * What's on: the day's listings in a panel over Today (owner, 27 Oct 2026: no Events page). Today
 * reads it only while the panel is open (?whats_on=1), as the panel's own partial reload asks.
 */
function whatsOn($test, array $query = []): ?array
{
    return $test->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'today',
        'X-Inertia-Partial-Data' => 'whatsOn',
    ])->get('/today?'.http_build_query(['whats_on' => 1, ...$query]))->assertOk()->json('props.whatsOn');
}

test('What’s on shows the chosen day, earlier hours included', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->onboarded()->create());
    Event::factory()->create(['title' => 'Morning talk', 'starts_at' => '2026-10-08 10:00:00', 'ends_at' => '2026-10-08 11:00:00']);
    Event::factory()->create(['title' => 'Evening concert', 'starts_at' => '2026-10-08 19:00:00', 'ends_at' => null]);
    Event::factory()->create(['title' => 'Next day', 'starts_at' => '2026-10-09 19:00:00']);

    $today = whatsOn($this);
    expect($today)->date->toBe('2026-10-08')->today->toBe('2026-10-08')
        ->and(array_column($today['listings'], 'title_original'))->toBe(['Morning talk', 'Evening concert']);

    $next = whatsOn($this, ['date' => '2026-10-09']);
    expect($next['date'])->toBe('2026-10-09')->and(array_column($next['listings'], 'title_original'))->toBe(['Next day']);
});

test('Today carries no What’s on unless its panel is open, and there is no Events page', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    expect($this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'today',
        'X-Inertia-Partial-Data' => 'whatsOn',
    ])->get('/today')->json('props.whatsOn'))->toBeNull();
    $this->get('/events')->assertNotFound();
    $this->get('/events/saved')->assertNotFound();
});

test('the filters come from the URL, and unknown values are dropped', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    DB::table('gtfs_stops')->insert(['stop_id' => 'neumarkt', 'stop_name' => 'Neumarkt', 'stop_lat' => 50.93576, 'stop_lng' => 6.94811]);

    expect(whatsOn($this, ['q' => ' chess ', 'category' => 'music', 'area' => 'Ehrenfeld', 'free' => 1, 'saved' => 1, 'no_german' => 1, 'sort' => 'closest', 'from' => 'Neumarkt'])['filters'])
        ->toBe(['q' => 'chess', 'category' => 'music', 'area' => 'Ehrenfeld', 'free' => true, 'saved' => true, 'no_german' => true, 'sort' => 'closest', 'from' => 'Neumarkt']);

    expect(whatsOn($this, ['category' => 'nonsense', 'sort' => 'random'])['filters'])
        ->category->toBeNull()->sort->toBe('soonest');
});

test('an invalid date opens today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->onboarded()->create());

    expect(whatsOn($this, ['date' => '2026-02-31'])['date'])->toBe('2026-10-08');
});

test('a saved place in the URL resolves only to the signed-in person’s own place', function () {
    $owner = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $owner->id, 'category' => 'home', 'lat' => 50.95, 'lng' => 6.91]);
    $other = User::factory()->onboarded()->create();

    $mine = whatsOn($this->actingAs($owner), ['from' => 'home', 'sort' => 'closest']);
    expect($mine['filters'])->from->toBe('home')->sort->toBe('closest')
        ->and($mine['starting_point'])->toBe(['key' => 'home', 'name' => 'Home', 'lat' => 50.95, 'lng' => 6.91])
        ->and($mine['saved_places'])->toBe([['key' => 'home', 'name' => 'Home', 'lat' => 50.95, 'lng' => 6.91]]);

    // Someone else's link with from=home never reaches the owner's home.
    $theirs = whatsOn($this->actingAs($other), ['from' => 'home', 'sort' => 'closest']);
    expect($theirs['filters'])->from->toBeNull()->sort->toBe('soonest')
        ->and($theirs['starting_point'])->toBeNull()
        ->and($theirs['saved_places'])->toBe([]);
});

test('a typed address in the URL is dropped, a public point is kept', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    DB::table('venues')->insert(['name' => 'Museum Ludwig', 'lat' => 50.9409, 'lng' => 6.9604, 'created_at' => now(), 'updated_at' => now()]);

    expect(whatsOn($this, ['from' => 'Venloer Straße 12, Köln']))
        ->filters->from->toBeNull()
        ->starting_point->toBeNull();

    $kept = whatsOn($this, ['from' => 'museum ludwig']);
    expect($kept['filters']['from'])->toBe('Museum Ludwig')->and($kept['starting_point']['name'])->toBe('Museum Ludwig');
});

test('starting point suggestions are posted, and only public points get a key', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    DB::table('venues')->insert(['name' => 'Museum Ludwig', 'lat' => 50.9409, 'lng' => 6.9604, 'created_at' => now(), 'updated_at' => now()]);
    $this->mock(GeocodingService::class, fn ($mock) => $mock->shouldReceive('search')->andReturn([
        ['name' => 'Museum Ludwig', 'street' => null, 'city' => 'Köln', 'lat' => 50.9409, 'lng' => 6.9604],
        ['name' => 'Venloer Straße 12', 'street' => 'Venloer Straße 12', 'city' => 'Köln', 'lat' => 50.95, 'lng' => 6.92],
    ]));

    $this->get('/api/events/starting-points?q=Museum')->assertStatus(405);

    $this->postJson(route('api.events.starting-points'), ['q' => 'Museum'])
        ->assertOk()
        ->assertJsonPath('0.name', 'Museum Ludwig')
        ->assertJsonPath('0.key', 'Museum Ludwig')
        ->assertJsonPath('1.name', 'Venloer Straße 12')
        ->assertJsonPath('1.key', null);
});

test('a listing without its start opens on its next occurrence, on that day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->onboarded()->create());
    $event = Event::factory()->create(['title' => 'Friday quiz', 'starts_at' => '2026-10-09 19:00:00']);

    $opened = whatsOn($this, ['event' => $event->id]);
    expect($opened['date'])->toBe('2026-10-09')
        ->and($opened['event']['event'])->toBe($event->id)
        ->and(CarbonImmutable::parse($opened['event']['start'])->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-10-09 19:00');
});

test('the panel’s data carries the weather, and a past day the next day with listings', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->create());
    Event::factory()->create(['starts_at' => CarbonImmutable::parse('2026-10-29 19:00', 'Europe/Berlin')->utc(), 'ends_at' => null]);

    expect(whatsOn($this))->toHaveKey('weather')->next_listed->toBeNull()
        ->and(whatsOn($this, ['date' => '2026-10-20'])['next_listed'])->toBe('2026-10-29');
});
