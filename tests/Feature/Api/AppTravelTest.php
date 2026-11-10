<?php

use App\Models\FavouriteStop;
use App\Models\User;
use App\Models\UserPlace;
use App\Transit\NearbyStation;

/* Travel on the iPhone: the web's Travel routes and props, with the app's token. */

test('🔒 Travel needs a token', function () {
    $this->getJson('/api/v1/travel/station?stop=Neumarkt')->assertUnauthorized();
    $this->getJson('/api/v1/travel/places')->assertUnauthorized();
    $this->putJson('/api/v1/travel/places/home', ['name' => 'Flat', 'lat' => 50.9, 'lng' => 6.9])->assertUnauthorized();
    $this->getJson('/api/v1/travel/favourite-stops')->assertUnauthorized();
    $this->postJson('/api/v1/travel/plan', ['to_lat' => 50.9, 'to_lng' => 6.9])->assertUnauthorized();
    $this->postJson('/api/v1/travel/planned-trips', [])->assertUnauthorized();
    $this->postJson('/api/v1/travel/trip', [])->assertUnauthorized();
});

test('🔒 Plan a trip\'s places are the person\'s own', function () {
    $person = User::factory()->onboarded()->create();
    UserPlace::factory()->for($person)->create(['name' => 'Office', 'category' => 'work', 'lat' => 50.94, 'lng' => 6.95]);
    UserPlace::factory()->for($person)->create(['name' => 'Flat', 'category' => 'home', 'lat' => 50.93, 'lng' => 6.94]);
    UserPlace::factory()->create(['name' => 'Someone else\'s home', 'category' => 'home', 'lat' => 50.92, 'lng' => 6.93]);
    FavouriteStop::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Rudolfplatz']);

    $places = $this->withToken($person->createToken('iPhone')->plainTextToken)->getJson('/api/v1/travel/places')->assertOk();

    // Home first, then Work; nobody else's place or ★ stop.
    expect(collect($places->json('saved_places'))->pluck('name')->all())->toBe(['Flat', 'Office'])
        ->and($places->json('favourite_stops'))->toBe([]);
});

test('🔒 Home set from Plan a trip is the person\'s own, and only Home or Work', function () {
    $person = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $token = $person->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->putJson('/api/v1/travel/places/home', ['name' => 'Flat', 'address' => 'Venloer Str. 1', 'lat' => 50.95, 'lng' => 6.92])
        ->assertOk()->assertJsonPath('place.name', 'Flat');
    $this->withToken($token)->putJson('/api/v1/travel/places/gym', ['name' => 'Gym', 'lat' => 50.95, 'lng' => 6.92])->assertNotFound();
    $this->withToken($token)->putJson('/api/v1/travel/places/work', ['name' => 'Office'])->assertUnprocessable();

    expect($person->places()->where('category', 'home')->value('name'))->toBe('Flat')
        ->and($other->places()->count())->toBe(0);
});

test('🔒 a station picked by name says whether it is the person\'s ★ stop', function () {
    $person = User::factory()->onboarded()->create();
    FavouriteStop::query()->create(['user_id' => User::factory()->create()->id, 'name' => 'Neumarkt']);
    $this->mock(NearbyStation::class)->shouldReceive('named')->with('Neumarkt')->andReturn(['name' => 'Neumarkt', 'lines' => []]);

    $this->withToken($person->createToken('iPhone')->plainTextToken)->getJson('/api/v1/travel/station?stop=Neumarkt')
        ->assertOk()->assertJsonPath('station.name', 'Neumarkt')->assertJsonPath('favourite', false);
});
