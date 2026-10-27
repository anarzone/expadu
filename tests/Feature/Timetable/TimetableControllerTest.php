<?php

use App\Models\User;
use App\Models\UserPlace;
use App\Transit\NearbyStation;
use Illuminate\Support\Facades\Redis;

test('the departures page renders with saved places as journey destinations', function () {
    $user = User::factory()->onboarded()->create();

    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'category' => 'home',
        'emoji' => '🏠',
        'lat' => 50.9384,
        'lng' => 6.9599,
        'sort_order' => 0,
    ]);
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Work',
        'category' => 'work',
        'emoji' => '💼',
        'lat' => 50.9413,
        'lng' => 6.9583,
        'sort_order' => 1,
    ]);

    $this->actingAs($user);

    $this->get(route('travel'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('timetable')
            ->has('savedPlaces', 2)
            ->has('savedPlaces.0', fn ($place) => $place
                ->where('name', 'Home')
                ->where('category', 'home')
                ->where('emoji', '🏠')
                ->has('lat')
                ->has('lng')
                ->etc()
            )
            ->where('savedPlaces.1.name', 'Work')
        );
});

test('saved places without coordinates are excluded from journey destinations', function () {
    $user = User::factory()->onboarded()->create();

    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'lat' => 50.94,
        'lng' => 6.95,
    ]);
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Somewhere without coords',
        'lat' => null,
        'lng' => null,
    ]);

    $this->actingAs($user);

    $this->get(route('travel'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('timetable')
            ->has('savedPlaces', 1)
            ->where('savedPlaces.0.name', 'Home')
        );
});

test('the departures page requires authentication', function () {
    $this->get(route('travel'))->assertRedirect(route('login'));
});

test('Nearby never uses a saved home or city centre when no location is known', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 50.9484,
        'lng' => 6.9599,
    ]);
    $this->partialMock(NearbyStation::class, function ($mock) {
        $mock->shouldReceive('closest')->never();
        $mock->shouldReceive('named')->never();
    });

    $this->actingAs($user)
        ->get(route('travel'))
        ->assertInertia(fn ($page) => $page
            ->component('timetable')
            ->loadDeferredProps(fn ($reload) => $reload->where('station', null))
        );
});

test('the debug trail names the station and why, never where the person was', function () {
    $this->partialMock(NearbyStation::class, fn ($m) => $m->shouldReceive('ready')->andReturn(false)->getMock()->shouldReceive('closest')->andReturn([
        'name' => 'Neumarkt', 'kind' => 'closest', 'source' => 'trias_rt', 'walk_min' => 3,
        'lines' => [['line' => '1', 'mode' => 'tram', 'area' => 'neumarkt-1-7-9', 'dirs' => []]],
    ]));

    $user = User::factory()->onboarded()->create();
    Redis::del("departure_debug:{$user->id}");
    // A confirmed location makes the resolved source deterministic (no geocode).
    Redis::setex("confirmed_location:{$user->id}", 7200, json_encode(['lat' => 50.9364, 'lng' => 6.9470, 'name' => 'Neumarkt']));

    $this->actingAs($user)->get(route('travel'))->assertInertia(fn ($page) => $page
        ->component('timetable')
        ->loadDeferredProps(fn ($reload) => $reload->where('station.name', 'Neumarkt'))
    );

    $entries = Redis::zrange("departure_debug:{$user->id}", 0, -1);
    $entry = json_decode($entries[array_key_last($entries)], true);
    expect($entry)->toMatchArray(['page' => 'timetable', 'stop' => 'Neumarkt', 'lines' => ['1'], 'location_source' => 'confirmed', 'lat' => null, 'lng' => null]);

    Redis::del("departure_debug:{$user->id}", "confirmed_location:{$user->id}");
});

test('the closest station is "closest" only from a fix of the last few minutes; from an older one it says where you last were', function () {
    $this->partialMock(NearbyStation::class, fn ($m) => $m->shouldReceive('ready')->andReturn(false)->getMock()->shouldReceive('closest')->andReturn([
        'name' => 'Merkenich Mitte', 'kind' => 'closest', 'source' => 'gtfs_static', 'walk_min' => 4, 'lines' => [],
    ]));
    $this->travelTo(now()->startOfMinute());
    $user = User::factory()->onboarded()->create();
    $confirmedAt = fn (int $minutesAgo) => Redis::setex("confirmed_location:{$user->id}", 7200, json_encode([
        'lat' => 51.0089, 'lng' => 6.9476, 'name' => 'Merkenich', 'at' => now()->subMinutes($minutesAgo)->timestamp,
    ]));

    $confirmedAt(3);
    $this->actingAs($user)->get(route('travel'))->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('station.located.fresh', true)
            ->where('station.located.source', 'confirmed')
            ->where('station.walk_min', 4)));

    $confirmedAt(120);
    $this->actingAs($user)->get(route('travel'))->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('station.name', 'Merkenich Mitte')
            ->where('station.located.fresh', false)
            ->where('station.located.at', now()->subMinutes(120)->toIso8601String())
            ->where('station.walk_min', null)));

    Redis::del("confirmed_location:{$user->id}");
});
