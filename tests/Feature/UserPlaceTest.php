<?php

use App\Models\User;
use App\Models\UserPlace;

test('user can create a place', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('user-places.store'), [
        'emoji' => '🏠',
        'name' => 'Home',
        'address' => 'Ehrenfeld, Cologne',
    ]);

    $response->assertRedirect();
    expect($user->places()->count())->toBe(1);
    expect($user->places()->first()->name)->toBe('Home');
    expect($user->places()->first()->emoji)->toBe('🏠');
});

test('user can create a place with coordinates', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->post(route('user-places.store'), [
        'emoji' => '💼',
        'name' => 'Work',
        'address' => 'Mediapark',
        'lat' => 50.9478,
        'lng' => 6.9183,
    ]);

    $place = $user->places()->first();
    expect($place->lat)->toBe(50.9478);
    expect($place->lng)->toBe(6.9183);
});

test('user can delete their own place', function () {
    $user = User::factory()->onboarded()->create();
    $place = UserPlace::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $response = $this->delete(route('user-places.destroy', $place));

    $response->assertRedirect();
    expect(UserPlace::find($place->id))->toBeNull();
});

test('user cannot delete another users place', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $place = UserPlace::factory()->create(['user_id' => $other->id]);
    $this->actingAs($user);

    $response = $this->delete(route('user-places.destroy', $place));

    $response->assertForbidden();
    expect(UserPlace::find($place->id))->not->toBeNull();
});

test('place creation validates required fields', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('user-places.store'), [
        'emoji' => '',
        'name' => '',
    ]);

    $response->assertSessionHasErrors(['emoji', 'name']);
});

test('profile page loads with user places', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->count(2)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $response = $this->get(route('profile'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('profile')
        ->has('userPlaces', 2)
    );
});
