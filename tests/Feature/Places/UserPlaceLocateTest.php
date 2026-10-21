<?php

use App\Models\User;
use App\Models\UserPlace;
use App\Services\GeocodingService;

/** One Photon feature, as GeocodingService reads it. */
function photonFeature(array $properties, float $lat = 50.9504, float $lng = 6.9161): array
{
    return ['geometry' => ['coordinates' => [$lng, $lat]], 'properties' => $properties];
}

/** Photon answers with these features (mapped as the service maps them). */
function fakePhoton(array $features): void
{
    test()->mock(GeocodingService::class, fn ($mock) => $mock->shouldReceive('search')
        ->andReturn((new GeocodingService)->mapFeatures($features)));
}

test('a typed address that is found exactly gets its point, marked as looked up', function () {
    fakePhoton([photonFeature(['name' => 'Venloer Straße', 'street' => 'Venloer Straße', 'housenumber' => '12a', 'postcode' => '50672', 'city' => 'Cologne', 'type' => 'house'])]);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->post(route('user-places.store'), ['emoji' => '🏠', 'name' => 'Home', 'address' => 'Venloer Str. 12a, 50672 Köln'])
        ->assertRedirect()
        ->assertSessionMissing('status');

    expect($user->places()->sole())
        ->lat->toBe(50.9504)
        ->lng->toBe(6.9161)
        ->located_from_address->toBeTrue();
});

test('an address found only nearly is not guessed: no point, and the person is told', function () {
    fakePhoton([photonFeature(['name' => 'Venloer Straße', 'street' => 'Venloer Straße', 'housenumber' => '14', 'postcode' => '50672', 'city' => 'Cologne', 'type' => 'house'])]);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->post(route('user-places.store'), ['emoji' => '🏠', 'name' => 'Home', 'address' => 'Venloer Str. 12a, 50672 Köln'])
        ->assertSessionHas('status', fn (string $note): bool => str_contains($note, 'couldn’t find that address'));

    expect($user->places()->sole())
        ->lat->toBeNull()
        ->lng->toBeNull()
        ->located_from_address->toBeFalse();
});

test('a picked suggestion keeps its point and looks nothing up', function () {
    $this->mock(GeocodingService::class, fn ($mock) => $mock->shouldNotReceive('search'));
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->post(route('user-places.store'), ['emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9478, 'lng' => 6.9183]);

    expect($user->places()->sole())->lat->toBe(50.9478)->located_from_address->toBeFalse();
});

test('an area by its name is found, a shop of the same name is not', function () {
    fakePhoton([photonFeature(['name' => 'Ehrenfeld', 'city' => 'Cologne', 'type' => 'district'], 50.95, 6.91)]);
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user)->post(route('user-places.store'), ['emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld, Köln']);
    expect($user->places()->sole()->lat)->toBe(50.95);

    fakePhoton([photonFeature(['name' => 'Home', 'street' => 'Aachener Straße', 'housenumber' => '3', 'city' => 'Cologne', 'type' => 'house'])]);
    $other = User::factory()->onboarded()->create();
    $this->actingAs($other)->post(route('user-places.store'), ['emoji' => '🏠', 'name' => 'Home', 'address' => 'Home']);
    expect($other->places()->sole()->lat)->toBeNull();
});

test('places saved without a point are looked up once, and a dry run saves nothing', function () {
    fakePhoton([photonFeature(['name' => 'Venloer Straße', 'street' => 'Venloer Straße', 'housenumber' => '12a', 'postcode' => '50672', 'city' => 'Cologne', 'type' => 'house'])]);
    $place = UserPlace::factory()->create(['address' => 'Venloer Straße 12a, 50672 Köln', 'lat' => null, 'lng' => null]);
    $kept = UserPlace::factory()->create(['address' => 'Somewhere 99', 'lat' => null, 'lng' => null]);

    $this->artisan('places:locate-addresses', ['--dry-run' => true])->assertSuccessful();
    expect($place->fresh()->lat)->toBeNull();

    $this->artisan('places:locate-addresses')->assertSuccessful();
    expect($place->fresh())->lat->toBe(50.9504)->located_from_address->toBeTrue()
        ->and($kept->fresh()->lat)->toBeNull();
});
