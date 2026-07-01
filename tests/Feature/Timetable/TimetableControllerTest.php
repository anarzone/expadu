<?php

use App\Models\User;
use App\Models\UserPlace;

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
