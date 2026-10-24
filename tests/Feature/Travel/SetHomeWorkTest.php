<?php

use App\Models\User;
use App\Models\UserPlace;

// Travel's "Add home" / "Add work": the place picked in Plan a trip's address
// line becomes your one Home (or Work), with its point. An older account's
// empty Home is filled, never doubled.

test('Add home makes your Home, with the place you picked', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user-places/home', ['name' => 'Ehrenfeld Bf', 'address' => 'Ehrenfeld', 'lat' => 50.9516, 'lng' => 6.9177])
        ->assertRedirect();

    $home = $user->places()->where('category', 'home')->sole();
    expect($home->name)->toBe('Ehrenfeld Bf')
        ->and((float) $home->lat)->toBe(50.9516)
        ->and((float) $home->lng)->toBe(6.9177);
});

test('an older account\'s empty Home is filled, not doubled, and Work is its own', function () {
    $user = User::factory()->create();
    UserPlace::query()->create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'category' => 'home', 'sort_order' => 0]);

    $this->actingAs($user)->put('/user-places/home', ['name' => 'Neumarkt', 'lat' => 50.9364, 'lng' => 6.9465])->assertRedirect();
    $this->actingAs($user)->put('/user-places/work', ['name' => 'Mediapark', 'lat' => 50.9480, 'lng' => 6.9440])->assertRedirect();

    expect($user->places()->where('category', 'home')->count())->toBe(1)
        ->and($user->places()->where('category', 'home')->value('name'))->toBe('Neumarkt')
        ->and($user->places()->where('category', 'work')->value('name'))->toBe('Mediapark');
});

test('a Home needs a point, and nobody sets anyone else\'s', function () {
    $this->put('/user-places/home', ['name' => 'Neumarkt', 'lat' => 50.9364, 'lng' => 6.9465])->assertRedirect('/login');

    $user = User::factory()->create();
    $this->actingAs($user)->putJson('/user-places/home', ['name' => 'Neumarkt'])->assertUnprocessable();
    $this->actingAs($user)->putJson('/user-places/garden', ['name' => 'Neumarkt', 'lat' => 50.9, 'lng' => 6.9])->assertNotFound();

    $other = User::factory()->create();
    $this->actingAs($other)->put('/user-places/home', ['name' => 'Kalk', 'lat' => 50.94, 'lng' => 7.0])->assertRedirect();
    expect($user->places()->count())->toBe(0);
});
