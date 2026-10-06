<?php

use App\Models\SavedJourney;
use App\Models\User;

function savedJourneyPayload(): array
{
    return [
        'title' => 'Work to the museum',
        'mode' => 'transit',
        'origin' => ['name' => 'Work', 'lat' => 50.935, 'lng' => 6.95],
        'destination' => ['name' => 'Museum', 'lat' => 50.941, 'lng' => 6.961, 'id' => 'osm:way/123'],
    ];
}

test('saving a request is idempotent and persists no itinerary or departure time', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user)->postJson('/api/saved-journeys', savedJourneyPayload())->assertSuccessful();
    $this->postJson('/api/saved-journeys', savedJourneyPayload())->assertSuccessful();
    expect(SavedJourney::query()->where('user_id', $user->id)->count())->toBe(1);
    $this->getJson('/api/saved-journeys')->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.request.destination.id', 'osm:way/123')
        ->assertJsonMissingPath('data.0.request.depart_at')
        ->assertJsonMissingPath('data.0.request.journey');
});

test('saved journeys cannot be read or removed by another account', function () {
    $owner = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $saved = $this->actingAs($owner)->postJson('/api/saved-journeys', savedJourneyPayload())->json('data.id');
    $this->actingAs($other)->getJson('/api/saved-journeys')->assertJsonCount(0, 'data');
    $this->deleteJson('/api/saved-journeys/'.$saved)->assertNotFound();
    $this->actingAs($owner)->deleteJson('/api/saved-journeys/'.$saved)->assertSuccessful();
    $this->getJson('/api/saved-journeys')->assertJsonCount(0, 'data');
});

test('saved journeys reject invalid coordinates and stale itinerary payloads', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    $this->postJson('/api/saved-journeys', array_replace_recursive(savedJourneyPayload(), ['destination' => ['lat' => 91]]))->assertUnprocessable();
    $this->postJson('/api/saved-journeys', savedJourneyPayload() + ['journey' => ['depart_at' => '2020-01-01']])->assertUnprocessable();
    $this->postJson('/api/saved-journeys', array_replace_recursive(savedJourneyPayload(), ['origin' => ['user_id' => 1]]))->assertUnprocessable();
});

test('saving a changed mode or starting point retains distinct requests', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user)->postJson('/api/saved-journeys', savedJourneyPayload())->assertSuccessful();
    $this->postJson('/api/saved-journeys', array_replace(savedJourneyPayload(), ['mode' => 'walk']))->assertSuccessful();
    $this->postJson('/api/saved-journeys', array_replace_recursive(savedJourneyPayload(), ['origin' => ['lat' => 50.9]]))->assertSuccessful();
    $this->getJson('/api/saved-journeys')->assertJsonCount(3, 'data');
});

test('saving a renamed request updates its title without duplicating it', function () {
    $this->actingAs(User::factory()->onboarded()->create())->postJson('/api/saved-journeys', savedJourneyPayload())->assertSuccessful();
    $this->postJson('/api/saved-journeys', array_replace(savedJourneyPayload(), ['title' => 'Evening trip']))->assertSuccessful();
    $this->getJson('/api/saved-journeys')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Evening trip');
});
