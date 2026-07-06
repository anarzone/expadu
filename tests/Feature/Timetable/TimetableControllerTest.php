<?php

use App\Models\User;
use App\Models\UserPlace;
use App\Services\TimetableService;
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

    $this->get(route('timetable'))
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

    $this->get(route('timetable'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('timetable')
            ->has('savedPlaces', 1)
            ->where('savedPlaces.0.name', 'Home')
        );
});

test('the departures page requires authentication', function () {
    $this->get(route('timetable'))->assertRedirect(route('login'));
});

test('building the board logs a departure debug entry with the location source', function () {
    $this->mock(TimetableService::class, function ($m) {
        $m->shouldReceive('boards')->andReturn([
            'all' => [
                'stop_name' => 'Neumarkt',
                'walk_min' => 2,
                'source' => 'trias_rt',
                'departures' => [
                    ['line' => '1', 'destination' => 'Bensberg', 'type' => 'tram', 'color' => '#E2001A', 'minutes' => [3], 'delay' => 0, 'cancelled' => false, 'disrupted' => false, 'direction' => 0, 'via' => []],
                ],
            ],
            'tram' => null,
            'bus' => null,
        ]);
    });

    $user = User::factory()->onboarded()->create();
    Redis::del("departure_debug:{$user->id}");
    // A confirmed location makes the resolved source deterministic (no geocode).
    Redis::setex("confirmed_location:{$user->id}", 7200, json_encode(['lat' => 50.9364, 'lng' => 6.9470, 'name' => 'Neumarkt']));

    $this->actingAs($user);

    // Loading the deferred boards prop runs the build — and its logging.
    $this->get(route('timetable'))->assertInertia(fn ($page) => $page
        ->component('timetable')
        ->loadDeferredProps(fn ($reload) => $reload->has('boards'))
    );

    $entries = Redis::zrange("departure_debug:{$user->id}", 0, -1);
    expect($entries)->not->toBeEmpty();

    $entry = json_decode($entries[array_key_last($entries)], true);
    expect($entry['page'])->toBe('timetable');
    expect($entry['stop'])->toBe('Neumarkt');
    expect($entry['lines'])->toBe(['1']);
    expect($entry['location_source'])->toBe('confirmed');

    Redis::del("departure_debug:{$user->id}", "confirmed_location:{$user->id}");
});
