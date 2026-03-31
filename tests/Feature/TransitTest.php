<?php

use App\Models\Routine;
use App\Models\User;

test('transit page renders for onboarded user', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('transit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('transit')
        ->has('routines')
        ->has('gtfsDepartures')
        ->has('userPlaces')
        ->has('currentStop')
    );
});

test('user can create a routine', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('routines.store'), [
        'name' => 'Morning Commute',
        'from_stop' => 'Ehrenfeld',
        'to_stop' => 'Neumarkt',
        'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
        'departure_time' => '08:15',
    ]);

    $response->assertRedirect();
    expect($user->routines()->count())->toBe(1);
});

test('user can toggle routine active state', function () {
    $user = User::factory()->onboarded()->create();
    $routine = Routine::factory()->create(['user_id' => $user->id, 'is_active' => true]);
    $this->actingAs($user);

    $this->put(route('routines.update', $routine), ['is_active' => false]);

    expect($routine->fresh()->is_active)->toBeFalse();
});

test('user cannot update another users routine', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $routine = Routine::factory()->create(['user_id' => $other->id]);
    $this->actingAs($user);

    $response = $this->put(route('routines.update', $routine), ['is_active' => false]);

    $response->assertForbidden();
});

test('user can delete their routine', function () {
    $user = User::factory()->onboarded()->create();
    $routine = Routine::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->delete(route('routines.destroy', $routine));

    expect(Routine::find($routine->id))->toBeNull();
});
