<?php

use App\Models\JourneyRecent;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Place;

test('suggest mixes saved places first with geocoder stations and addresses', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Vita Gym',
        'emoji' => '🏋️',
        'lat' => 50.94,
        'lng' => 6.95,
    ]);
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 50.9513,
        'lng' => 6.9185,
    ]);

    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('geocode')->once()->with('vita', Mockery::on(fn ($bias) => $bias instanceof GeoPoint
            && $bias->lat === (float) config('geocoding.search_default.lat')
            && $bias->lng === (float) config('geocoding.search_default.lng')))->andReturn([
                new Place('Köln Vitalisstr. Nord', new GeoPoint(50.9512, 6.8947), stopId: 'de:vrs:1', kind: 'stop', area: 'Bickendorf · Köln'),
                new Place('Vitalisstraße 204', new GeoPoint(50.9530, 6.8926), kind: 'address', area: 'Bickendorf · Köln'),
            ]);
    });

    $this->actingAs($user)
        ->get('/api/journey/suggest?q=vita')
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonPath('0.kind', 'saved')
        ->assertJsonPath('0.name', 'Vita Gym')
        ->assertJsonPath('0.emoji', '🏋️')
        ->assertJsonPath('1.kind', 'stop')
        ->assertJsonPath('1.name', 'Köln Vitalisstr. Nord')
        ->assertJsonPath('1.area', 'Bickendorf · Köln')
        ->assertJsonPath('2.kind', 'address')
        ->assertJsonPath('2.name', 'Vitalisstraße 204');
});

test('an empty query returns recents and saved places as defaults', function () {
    $user = User::factory()->onboarded()->create();

    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'emoji' => '🏠',
        'lat' => 50.94,
        'lng' => 6.95,
    ]);
    JourneyRecent::record($user->id, 'destination', 'Vitalisstraße 204', 50.953, 6.8926, 'Bickendorf · Köln');

    $this->actingAs($user)
        ->getJson('/api/journey/suggest')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.kind', 'recent')
        ->assertJsonPath('0.name', 'Vitalisstraße 204')
        ->assertJsonPath('1.kind', 'saved')
        ->assertJsonPath('1.name', 'Home');
});

test('origin defaults come from origin recents', function () {
    $user = User::factory()->onboarded()->create();

    JourneyRecent::record($user->id, 'origin', 'Köln Merkenich Mitte', 51.024, 6.953);
    JourneyRecent::record($user->id, 'destination', 'Somewhere Else', 50.9, 6.9);

    $this->actingAs($user)
        ->getJson('/api/journey/suggest?role=origin')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'Köln Merkenich Mitte');
});

test('a repeat journey bumps the recent instead of duplicating it', function () {
    $user = User::factory()->onboarded()->create();

    JourneyRecent::record($user->id, 'destination', 'Dom', 50.9413, 6.9583);
    JourneyRecent::record($user->id, 'destination', 'Dom', 50.9413, 6.9583);

    expect(JourneyRecent::where('user_id', $user->id)->count())->toBe(1)
        ->and(JourneyRecent::first()->times_used)->toBe(2);
});

test('fresh account search uses configured city bias and recognises local and English aliases', function (?string $city) {
    config(['geocoding.search_default' => [
        'city' => 'Cologne',
        'aliases' => ['Köln', 'Koeln'],
        'lat' => 50.935,
        'lng' => 6.94,
    ]]);
    $user = User::factory()->onboarded()->create(['city' => $city, 'veedel' => null]);
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Neumarkt saved place',
        'lat' => 50.935,
        'lng' => 6.948,
    ]);
    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('geocode')->once()
            ->with('Neumarkt', Mockery::on(fn ($bias) => $bias instanceof GeoPoint && $bias->lat === 50.935 && $bias->lng === 6.94))
            ->andReturn([
                new Place('Neumarkt', new GeoPoint(49.48, 20.03), kind: 'stop', area: 'Nowy Targ · Poland'),
                new Place('Neumarkt', new GeoPoint(50.936, 6.949), kind: 'stop', area: 'Altstadt-Süd · Köln'),
                new Place('Neumarkt entrance', new GeoPoint(50.9361, 6.9491), kind: 'place', area: 'Cologne'),
            ]);
    });

    $this->actingAs($user)->getJson('/api/journey/suggest?q=Neumarkt')
        ->assertSuccessful()
        ->assertJsonCount(4)
        ->assertJsonPath('0.kind', 'saved')
        ->assertJsonPath('1.area', 'Altstadt-Süd · Köln')
        ->assertJsonPath('2.area', 'Cologne')
        ->assertJsonPath('3.area', 'Nowy Targ · Poland');

    expect(app(UserLocationService::class)->context($user)->hasOrigin())->toBeFalse();
})->with([null, 'Cologne', 'Köln']);

test('an explicit search origin overrides the configured city without local-name reranking', function () {
    config(['geocoding.search_default' => [
        'city' => 'Cologne',
        'aliases' => ['Köln'],
        'lat' => 50.9375,
        'lng' => 6.9603,
    ]]);
    $user = User::factory()->onboarded()->create(['city' => 'Cologne', 'veedel' => null]);
    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('geocode')->once()
            ->with('Neumarkt', Mockery::on(fn ($bias) => $bias instanceof GeoPoint && $bias->lat === 49.48 && $bias->lng === 20.03))
            ->andReturn([
                new Place('Neumarkt', new GeoPoint(49.48, 20.03), kind: 'stop', area: 'Nowy Targ · Poland'),
                new Place('Neumarkt', new GeoPoint(50.936, 6.949), kind: 'stop', area: 'Altstadt-Süd · Köln'),
            ]);
    });

    $this->actingAs($user)->getJson('/api/journey/suggest?q=Neumarkt&lat=49.48&lng=20.03')
        ->assertSuccessful()
        ->assertJsonPath('0.area', 'Nowy Targ · Poland')
        ->assertJsonPath('1.area', 'Altstadt-Süd · Köln');
});
