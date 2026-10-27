<?php

use App\Models\Event;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\GeocodingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('events page shows the chosen day, earlier hours included', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->onboarded()->create());
    Event::factory()->create(['title' => 'Morning talk', 'starts_at' => '2026-10-08 10:00:00', 'ends_at' => '2026-10-08 11:00:00']);
    Event::factory()->create(['title' => 'Evening concert', 'starts_at' => '2026-10-08 19:00:00', 'ends_at' => null]);
    Event::factory()->create(['title' => 'Next day', 'starts_at' => '2026-10-09 19:00:00']);

    $this->get(route('events'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('events')
            ->where('date', '2026-10-08')
            ->where('today', '2026-10-08')
            ->has('listings', 2)
            ->where('listings.0.title_original', 'Morning talk')
            ->where('listings.1.title_original', 'Evening concert'));

    $this->get(route('events', ['date' => '2026-10-09']))
        ->assertInertia(fn ($page) => $page
            ->where('date', '2026-10-09')
            ->has('listings', 1)
            ->where('listings.0.title_original', 'Next day'));
});

test('the filters come from the URL, and unknown values are dropped', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    DB::table('gtfs_stops')->insert(['stop_id' => 'neumarkt', 'stop_name' => 'Neumarkt', 'stop_lat' => 50.93576, 'stop_lng' => 6.94811]);

    $this->get(route('events', ['q' => ' chess ', 'category' => 'culture', 'area' => 'Ehrenfeld', 'free' => 1, 'saved' => 1, 'sort' => 'closest', 'from' => 'Neumarkt']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.q', 'chess')
            ->where('filters.category', 'culture')
            ->where('filters.area', 'Ehrenfeld')
            ->where('filters.free', true)
            ->where('filters.saved', true)
            ->where('filters.sort', 'closest')
            ->where('filters.from', 'Neumarkt'));

    $this->get(route('events', ['category' => 'nonsense', 'sort' => 'random']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.category', null)
            ->where('filters.sort', 'soonest'));
});

test('an invalid date is refused', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('events', ['date' => '2026-02-31']))->assertSessionHasErrors('date');
});

test('a saved place in the URL resolves only to the signed-in person’s own place', function () {
    $owner = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $owner->id, 'category' => 'home', 'lat' => 50.95, 'lng' => 6.91]);
    $other = User::factory()->onboarded()->create();

    $this->actingAs($owner)->get(route('events', ['from' => 'home', 'sort' => 'closest']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', 'home')
            ->where('filters.sort', 'closest')
            ->where('starting_point', ['key' => 'home', 'name' => 'Home', 'lat' => 50.95, 'lng' => 6.91])
            ->where('saved_places', [['key' => 'home', 'name' => 'Home', 'lat' => 50.95, 'lng' => 6.91]]));

    // Someone else's link with from=home never reaches the owner's home.
    $this->actingAs($other)->get(route('events', ['from' => 'home', 'sort' => 'closest']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', null)
            ->where('filters.sort', 'soonest')
            ->where('starting_point', null)
            ->where('saved_places', []));
});

test('a typed address in the URL is dropped, a public point is kept', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    DB::table('venues')->insert(['name' => 'Museum Ludwig', 'lat' => 50.9409, 'lng' => 6.9604, 'created_at' => now(), 'updated_at' => now()]);

    $this->get(route('events', ['from' => 'Venloer Straße 12, Köln']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', null)
            ->where('starting_point', null));

    $this->get(route('events', ['from' => 'museum ludwig']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', 'Museum Ludwig')
            ->where('starting_point.name', 'Museum Ludwig'));
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

test('legacy detail and saved URLs redirect to the events page', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    $event = Event::factory()->create();

    $response = $this->get(route('events.show', $event))->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith(route('events').'?event='.$event->id.'&start=');
    $this->get(route('events.saved'))->assertRedirect(route('events', ['saved' => 1]));
});
