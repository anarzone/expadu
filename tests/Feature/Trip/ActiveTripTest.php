<?php

use App\Models\ActiveTrip;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

/** A minimal but valid trip payload the start endpoint accepts. */
function tripPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'journey' => [
            'mode' => 'transit',
            'depart_at' => '2026-07-03T18:00:00+02:00',
            'arrive_at' => '2026-07-03T18:24:00+02:00',
            'depart_time' => '18:00',
            'arrive_time' => '18:24',
            'duration_min' => 24,
            'transfers' => 1,
            'legs' => [
                ['mode' => 'tram', 'line' => '12'],
            ],
        ],
        'destination' => [
            'name' => 'Ebertplatz',
            'lat' => 50.9506,
            'lng' => 6.9588,
            'emoji' => '📍',
        ],
        'origin' => [
            'name' => 'Neumarkt',
            'lat' => 50.9365,
            'lng' => 6.9478,
        ],
    ], $overrides);
}

test('starting a trip persists it for the user', function () {
    $this->postJson('/api/trip/start', tripPayload())
        ->assertOk()
        ->assertJsonPath('trip.destination.name', 'Ebertplatz')
        ->assertJsonPath('trip.journey.arrive_time', '18:24');

    $trip = ActiveTrip::query()->where('user_id', $this->user->id)->sole();

    expect($trip->destination['name'])->toBe('Ebertplatz');
    expect($trip->origin['name'])->toBe('Neumarkt');
    expect($trip->journey['legs'][0]['line'])->toBe('12');
    expect($trip->started_at)->not->toBeNull();
});

test('starting a new trip replaces the old one (one row per user)', function () {
    $this->postJson('/api/trip/start', tripPayload())->assertOk();
    $this->postJson('/api/trip/start', tripPayload([
        'destination' => ['name' => 'Rudolfplatz'],
    ]))->assertOk();

    expect(ActiveTrip::query()->where('user_id', $this->user->id)->count())->toBe(1);
    expect($this->user->activeTrip->destination['name'])->toBe('Rudolfplatz');
});

test('ending a trip clears it', function () {
    $this->postJson('/api/trip/start', tripPayload())->assertOk();

    $this->postJson('/api/trip/end')
        ->assertOk()
        ->assertJson(['ended' => true]);

    expect(ActiveTrip::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('start requires a journey with legs and a destination', function () {
    $this->postJson('/api/trip/start', [
        'journey' => ['legs' => []],
        'destination' => ['name' => 'X'],
    ])->assertUnprocessable();
});

test('the active trip is shared with every page', function () {
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('activeTrip', null));

    $this->postJson('/api/trip/start', tripPayload())->assertOk();

    // A real request resolves a fresh user each time; drop the cached (null)
    // relation the first GET loaded onto the acting-as instance.
    $this->user->unsetRelation('activeTrip');

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('activeTrip.destination.name', 'Ebertplatz')
        ->has('activeTrip.journey')
    );
});

test('another user does not see this user’s trip', function () {
    $this->postJson('/api/trip/start', tripPayload())->assertOk();

    $other = User::factory()->onboarded()->create();
    $this->actingAs($other);

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('activeTrip', null));
});
