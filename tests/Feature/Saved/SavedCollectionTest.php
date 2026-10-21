<?php

use App\Models\Event;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Europe/Berlin'));
});

function savedEvent(): Event
{
    return Event::factory()->create([
        'title' => 'A saved evening', 'title_en' => null, 'recurrence' => null,
        'starts_at' => '2026-10-08 18:00:00', 'ends_at' => '2026-10-08 20:00:00',
        'status' => 'active', 'is_curated' => true,
    ]);
}

test('🔒 saved places and events come back together, newest first, and only the signed-in person\'s', function () {
    $owner = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $event = savedEvent();
    $place = Spot::factory()->create(['name' => 'Bau', 'category' => 'cafe', 'is_active' => true]);
    $place->forceFill(['public_access_allowed' => true])->save();

    $this->actingAs($owner);
    $this->postJson('/api/event-bookmarks', ['event_id' => $event->id, 'occurrence_start' => $event->starts_at->toIso8601String(), 'saved' => true])->assertSuccessful();
    $this->travel(5)->minutes();
    SpotFeedback::factory()->create(['user_id' => $owner->id, 'spot_id' => $place->id]);
    // Someone else's saves never show.
    SpotFeedback::factory()->create(['user_id' => $other->id]);

    $this->getJson('/api/saved')->assertSuccessful()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('data.0.type', 'place')
        ->assertJsonPath('data.0.title', 'Bau')
        ->assertJsonPath('data.0.available', true)
        ->assertJsonPath('data.1.type', 'event')
        ->assertJsonPath('data.1.id', $event->id)
        ->assertJsonPath('data.1.date', '2026-10-08')
        ->assertJsonPath('data.1.start', '18:00');

    $this->actingAs($other);
    $this->getJson('/api/saved')->assertSuccessful()->assertJsonPath('count', 1)->assertJsonPath('data.0.type', 'place');
});

test('a saved event that is gone stays, unavailable, instead of vanishing', function () {
    $owner = User::factory()->onboarded()->create();
    $event = savedEvent();
    $this->actingAs($owner);
    $this->postJson('/api/event-bookmarks', ['event_id' => $event->id, 'occurrence_start' => $event->starts_at->toIso8601String(), 'saved' => true])->assertSuccessful();
    $event->update(['status' => 'hidden']);

    $this->getJson('/api/saved')->assertJsonPath('count', 1)->assertJsonPath('data.0.available', false);
});

test('🔒 guests get nothing', function () {
    $this->getJson('/api/saved')->assertUnauthorized();
});
