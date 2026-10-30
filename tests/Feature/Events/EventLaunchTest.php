<?php

use App\Models\Event;
use App\Models\EventReminder;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Europe/Berlin'));
    $this->actingAs(User::factory()->onboarded()->create());
});

function launchEvent(array $attributes = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'A local event', 'title_en' => null, 'recurrence' => null,
        'starts_at' => '2026-10-08 18:00:00', 'ends_at' => '2026-10-08 20:00:00',
        'status' => 'active', 'is_curated' => true,
    ], $attributes));
}

test('a chosen calendar date returns that day rather than today', function () {
    $event = launchEvent();
    launchEvent(['starts_at' => '2026-10-05 12:00:00']);

    $this->getJson('/api/events?date=2026-10-08')->assertSuccessful()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $event->id);
    $this->getJson('/api/events?date=2026-02-30')->assertUnprocessable();
});

test('Sunday weekend includes the remaining Sunday events', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'Europe/Berlin'));
    $event = launchEvent(['starts_at' => '2026-10-11 18:00:00', 'ends_at' => '2026-10-11 20:00:00']);
    $this->getJson('/api/events?window=weekend')->assertSuccessful()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $event->id);
});

test('saved events persist exact occurrences privately and repeated saves are idempotent', function () {
    $event = launchEvent();
    $owner = auth()->user();
    $payload = ['event_id' => $event->id, 'occurrence_start' => $event->starts_at->toIso8601String(), 'saved' => true];
    $this->postJson('/api/event-bookmarks', $payload)->assertSuccessful();
    $this->postJson('/api/event-bookmarks', $payload)->assertSuccessful();
    $this->getJson('/api/event-bookmarks')->assertSuccessful()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.event_id', $event->id)->assertJsonPath('data.0.event.id', $event->id);

    $this->actingAs(User::factory()->onboarded()->create());
    $this->getJson('/api/event-bookmarks')->assertSuccessful()->assertJsonCount(0, 'data');
    $this->postJson('/api/event-bookmarks', [...$payload, 'saved' => false])->assertSuccessful();
    $this->actingAs($owner);
    $this->getJson('/api/event-bookmarks')->assertJsonCount(1, 'data');
});

test('unavailable and invented event occurrences cannot be saved or reminded', function () {
    $event = launchEvent();
    $fakeStart = '2026-10-09T18:00:00+02:00';
    $this->postJson('/api/event-bookmarks', ['event_id' => $event->id, 'occurrence_start' => $fakeStart, 'saved' => true])->assertUnprocessable();
    $this->postJson('/api/reminders', ['event_id' => $event->id, 'occurrence_start' => $fakeStart, 'offset' => '1h'])->assertUnprocessable();
    $event->update(['status' => 'hidden']);
    $this->postJson('/api/event-bookmarks', ['event_id' => $event->id, 'occurrence_start' => $event->starts_at->toIso8601String(), 'saved' => true])->assertUnprocessable();
});

test('removing a bookmark preserves its reminder and unavailable saved items stay removable', function () {
    $event = launchEvent();
    $payload = ['event_id' => $event->id, 'occurrence_start' => $event->starts_at->toIso8601String(), 'saved' => true];
    $this->postJson('/api/event-bookmarks', $payload)->assertSuccessful();
    $this->postJson('/api/reminders', [...$payload, 'offset' => '1h'])->assertSuccessful();
    $event->update(['status' => 'hidden']);
    $this->getJson('/api/event-bookmarks')->assertJsonPath('data.0.event', null)->assertJsonPath('data.0.available', false);
    $this->postJson('/api/event-bookmarks', [...$payload, 'saved' => false])->assertSuccessful();
    $this->getJson('/api/event-bookmarks')->assertJsonCount(0, 'data');
    expect(EventReminder::where('user_id', auth()->id())->count())->toBe(1);
});
