<?php

use App\Models\User;
use App\Models\UserPlace;
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

    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('geocode')->once()->andReturn([
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

test('suggest rejects queries that are too short', function () {
    $this->actingAs(User::factory()->onboarded()->create())
        ->getJson('/api/journey/suggest?q=v')
        ->assertUnprocessable();
});
