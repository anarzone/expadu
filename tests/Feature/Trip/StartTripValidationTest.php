<?php

use App\Models\ActiveTrip;
use App\Models\User;

function datedTripPayload(): array
{
    return [
        'journey' => [
            'mode' => 'transit', 'depart_at' => now()->addMinute()->toIso8601String(),
            'arrive_at' => now()->addMinutes(20)->toIso8601String(),
            'legs' => [[
                'mode' => 'tram', 'line' => '1',
                'depart_at' => now()->addMinute()->toIso8601String(),
                'arrive_at' => now()->addMinutes(20)->toIso8601String(),
                'to' => ['name' => 'Destination'],
            ]],
        ],
        'destination' => ['name' => 'Destination', 'lat' => 50.94, 'lng' => 6.95],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('an ended or already departed transit itinerary cannot start', function () {
    $payload = datedTripPayload();
    $this->travel(2)->minutes();
    $this->postJson('/api/trip/start', $payload)->assertUnprocessable();
    expect($this->user->activeTrip()->exists())->toBeFalse();

    $this->travel(25)->minutes();
    $this->postJson('/api/trip/start', $payload)->assertUnprocessable();
    expect($this->user->activeTrip()->exists())->toBeFalse();
});

test('malformed or backwards leg times do not replace an existing active trip', function ($badTime) {
    $payload = datedTripPayload();
    $this->postJson('/api/trip/start', $payload)->assertSuccessful();
    $before = ActiveTrip::where('user_id', $this->user->id)->sole()->toArray();
    $payload['journey']['legs'][0]['arrive_at'] = $badTime;
    $this->postJson('/api/trip/start', $payload)->assertUnprocessable();
    expect(ActiveTrip::where('user_id', $this->user->id)->sole()->toArray())->toBe($before);
})->with(['now', '2026-02-30T12:00:00+01:00', '2000-01-01T00:00:00Z']);

test('reopening the same current trip keeps its original start after boarding', function () {
    $payload = datedTripPayload();
    $this->postJson('/api/trip/start', $payload)->assertSuccessful();
    $startedAt = ActiveTrip::where('user_id', $this->user->id)->sole()->started_at->toIso8601String();
    $this->travel(2)->minutes();
    $this->postJson('/api/trip/start', $payload)->assertSuccessful();
    expect(ActiveTrip::where('user_id', $this->user->id)->sole()->started_at->toIso8601String())->toBe($startedAt);
});
