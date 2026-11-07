<?php

use App\Models\User;
use App\Services\NearbyStopService;

/* Travel's board for the iPhone: only a confirmed account reads it, in one list, soonest first. */

test('🔒 the board needs a token and a confirmed address', function () {
    $this->getJson('/api/v1/travel/nearby?lat=50.9356&lng=6.948')->assertUnauthorized();

    $unconfirmed = User::factory()->unverified()->create();
    $this->withToken($unconfirmed->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/travel/nearby?lat=50.9356&lng=6.948')->assertForbidden();
});

test('departures come soonest first, in KVB colours, without lines that have none left', function () {
    $this->mock(NearbyStopService::class)->shouldReceive('getDeparturesByType')->andReturn([
        'kvb' => [
            ['line' => '4', 'direction' => 'Schlebusch', 'type' => 'tram', 'departures' => [6, 16, 26, 36], 'delay' => 0, 'cancelled' => false, 'stop_name' => 'Neumarkt', 'walk_min' => 1, 'disrupted' => false],
            ['line' => '1', 'direction' => 'Brück', 'type' => 'tram', 'departures' => [2], 'delay' => 3, 'cancelled' => false, 'stop_name' => 'Neumarkt', 'walk_min' => 1, 'disrupted' => true],
            ['line' => '9', 'direction' => 'Sülz', 'type' => 'tram', 'departures' => [], 'stop_name' => 'Neumarkt', 'walk_min' => 1],
        ],
        'db' => [],
        'stops_used' => [['name' => 'Neumarkt', 'walk_min' => 1, 'distance_m' => 19]],
    ]);

    $this->withToken(User::factory()->create()->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/travel/nearby?lat=50.9356&lng=6.948')->assertOk()
        ->assertJsonPath('stops.0.name', 'Neumarkt')
        ->assertJsonCount(2, 'departures')
        ->assertJsonPath('departures.0.line', '1')
        ->assertJsonPath('departures.0.color', '#E0071C')
        ->assertJsonPath('departures.0.delay', 3)
        ->assertJsonPath('departures.0.disrupted', true)
        ->assertJsonPath('departures.1.minutes', [6, 16, 26])
        ->assertJsonPath('departures.1.color', '#EA71A5');
});
