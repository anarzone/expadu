<?php

use App\Models\User;
use App\Services\GeocodingService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Place;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

// What a person types into a place search (a home address, a friend's
// street) is answered and forgotten: it travels in a POST body, never a URL
// or an access log, and no search log keeps it.

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    Redis::del("search_debug:{$this->user->id}");
});

test('a typed address is searched by POST and nothing records it', function () {
    $this->mock(GeocodingService::class, fn ($mock) => $mock->shouldReceive('search')
        ->once()->with('Venloer Straße 40')
        ->andReturn([['name' => 'Venloer Straße 40', 'street' => 'Venloer Straße', 'city' => 'Köln', 'lat' => 50.9445, 'lng' => 6.9369]]));

    $this->actingAs($this->user)
        ->postJson(route('api.geocode.search'), ['q' => 'Venloer Straße 40'])
        ->assertOk()
        ->assertJsonPath('0.name', 'Venloer Straße 40');

    expect(Redis::exists("search_debug:{$this->user->id}"))->toBe(0);
});

test('the older GET search no longer logs what was typed either', function () {
    $this->mock(GeocodingService::class, fn ($mock) => $mock->shouldReceive('search')->once()->andReturn([]));

    $this->actingAs($this->user)
        ->getJson(route('api.geocode', ['q' => 'Venloer Straße 40']))
        ->assertOk();

    expect(Redis::exists("search_debug:{$this->user->id}"))->toBe(0);
});

test('Plan a trip\'s suggestions take the typed text in the body', function () {
    $this->mock(RouteService::class, fn ($mock) => $mock->shouldReceive('geocode')
        ->once()->with('Wiener Platz', Mockery::type(GeoPoint::class))
        ->andReturn([new Place('Wiener Platz', new GeoPoint(50.9612, 7.0040), kind: 'stop', area: 'Mülheim · Köln')]));

    $this->actingAs($this->user)
        ->postJson(route('api.journey.suggest.search'), ['q' => 'Wiener Platz', 'role' => 'destination'])
        ->assertOk()
        ->assertJsonPath('0.name', 'Wiener Platz')
        ->assertJsonPath('0.kind', 'stop');
});

test('the body searches need a signed-in person and keep the search budget', function () {
    $this->postJson(route('api.geocode.search'), ['q' => 'Köln'])->assertUnauthorized();
    $this->postJson(route('api.journey.suggest.search'), ['q' => 'Köln'])->assertUnauthorized();
    $this->postJson(route('api.journey.plan'), ['to_lat' => 50.94, 'to_lng' => 6.96])->assertUnauthorized();

    foreach (['api.geocode.search' => 'throttle:search', 'api.journey.suggest.search' => 'throttle:search', 'api.journey.plan' => 'throttle:journey'] as $name => $throttle) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain($throttle);
    }
});
