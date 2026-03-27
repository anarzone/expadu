<?php

use App\Models\Spot;
use App\Models\SpotCheckin;
use App\Models\User;
use App\Models\UserPlace;

test('explore page renders with spots', function () {
    $user = User::factory()->onboarded()->create();
    Spot::factory()->count(3)->create();
    $this->actingAs($user);

    $response = $this->get(route('explore'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('explore')
        ->has('spots.data', 3)
    );
});

test('explore page includes personal places', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->count(2)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $response = $this->get(route('explore'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('explore')
        ->has('personalPlaces', 2)
    );
});

test('spots can be filtered by category', function () {
    $user = User::factory()->onboarded()->create();
    Spot::factory()->create(['category' => 'cafe']);
    Spot::factory()->create(['category' => 'library']);
    $this->actingAs($user);

    $response = $this->get(route('explore', ['category' => 'cafe']));

    $response->assertInertia(fn ($page) => $page->has('spots.data', 1));
});

test('user can check into a spot', function () {
    $user = User::factory()->onboarded()->create();
    $spot = Spot::factory()->create();
    $this->actingAs($user);

    $this->post(route('spots.checkin', $spot));

    expect(SpotCheckin::where('user_id', $user->id)->where('spot_id', $spot->id)->count())->toBe(1);
});

test('user can check out of a spot', function () {
    $user = User::factory()->onboarded()->create();
    $spot = Spot::factory()->create();
    SpotCheckin::create([
        'spot_id' => $spot->id,
        'user_id' => $user->id,
        'checked_in_at' => now(),
    ]);
    $this->actingAs($user);

    $this->post(route('spots.checkout', $spot));

    $checkin = SpotCheckin::where('user_id', $user->id)->first();
    expect($checkin->checked_out_at)->not->toBeNull();
});

test('duplicate checkin is prevented', function () {
    $user = User::factory()->onboarded()->create();
    $spot = Spot::factory()->create();
    SpotCheckin::create([
        'spot_id' => $spot->id,
        'user_id' => $user->id,
        'checked_in_at' => now(),
    ]);
    $this->actingAs($user);

    $this->post(route('spots.checkin', $spot));

    expect(SpotCheckin::where('user_id', $user->id)->where('spot_id', $spot->id)->count())->toBe(1);
});
