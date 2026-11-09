<?php

use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use Carbon\CarbonImmutable;

/* What's on for the iPhone: a confirmed account reads a day's listings, its own saves marked. */

test('🔒 What’s on needs a token and a confirmed address', function () {
    $this->getJson('/api/v1/events')->assertUnauthorized();

    $unconfirmed = User::factory()->unverified()->create();
    $this->withToken($unconfirmed->createToken('iPhone')->plainTextToken)->getJson('/api/v1/events')->assertForbidden();
});

test('a day’s listings come with the days ahead, and only this person’s saves are marked', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00', 'Europe/Berlin'));
    $start = CarbonImmutable::parse('2026-11-08 19:00', 'Europe/Berlin');
    $event = Event::factory()->create(['status' => 'active', 'starts_at' => $start, 'ends_at' => $start->addHours(2), 'category' => 'music', 'price_text' => 'kostenlos']);
    $user = User::factory()->create();
    EventBookmark::create(['user_id' => User::factory()->create()->id, 'event_id' => $event->id, 'occurrence_start' => $start, 'title' => $event->title]);

    $mine = $this->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/events?date=2026-11-08')->assertOk()
        ->assertJsonPath('date', '2026-11-08')
        ->assertJsonPath('days.1', ['date' => '2026-11-08', 'count' => 1])
        ->assertJsonPath('categories.0.id', 'music')
        ->assertJsonPath('listings.0.id', $event->id)
        ->assertJsonPath('listings.0.free', true)
        ->assertJsonPath('listings.0.saved', false);
    expect($mine->json('days'))->toHaveCount(7);
});

test('a past or far-off day reads as today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00', 'Europe/Berlin'));
    $token = User::factory()->create()->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/events?date=2026-11-01')->assertOk()->assertJsonPath('date', '2026-11-07');
    $this->withToken($token)->getJson('/api/v1/events?date=2027-01-01')->assertOk()->assertJsonPath('date', '2026-11-07');
});

test('an event on now can be saved; one that has ended can’t', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 20:15', 'Europe/Berlin'));
    $token = User::factory()->create()->createToken('iPhone')->plainTextToken;
    $tour = CarbonImmutable::parse('2026-11-09 19:00', 'Europe/Berlin');
    $onNow = Event::factory()->create(['status' => 'active', 'starts_at' => $tour, 'ends_at' => $tour->addHours(2)]);
    $matinee = CarbonImmutable::parse('2026-11-09 10:00', 'Europe/Berlin');
    $ended = Event::factory()->create(['status' => 'active', 'starts_at' => $matinee, 'ends_at' => $matinee->addMinutes(45)]);

    $this->withToken($token)->postJson('/api/v1/events/saved', ['event_id' => $onNow->id, 'occurrence_start' => $tour->toIso8601String(), 'saved' => true])
        ->assertOk()->assertJsonPath('saved', true);
    $this->withToken($token)->postJson('/api/v1/events/saved', ['event_id' => $ended->id, 'occurrence_start' => $matinee->toIso8601String(), 'saved' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('occurrence_start');
});
