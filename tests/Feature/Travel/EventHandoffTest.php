<?php

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;

// Events' "Get there" hands an event to Plan a trip by its id and the
// occurrence's public start, never by coordinates: the server finds the
// occurrence as Events lists it, and a hidden, unknown or finished one
// gives the page nothing.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'Europe/Berlin'));
    $this->user = User::factory()->onboarded()->create();
    $this->venue = Venue::query()->create(['name' => 'Stadtgarten', 'lat' => 50.9405, 'lng' => 6.9343, 'veedel' => 'Belgisches Viertel']);
});

function handoffUrl(Event $event, ?string $start = null): string
{
    return '/timetable?'.http_build_query([
        'view' => 'journey',
        'event' => $event->id,
        'start' => $start ?? CarbonImmutable::parse($event->starts_at)->toIso8601String(),
    ]);
}

test('Plan a trip finds a visible, current occurrence by its id and start, and nothing else', function () {
    $tonight = Event::factory()->create([
        'title' => 'Jazz night', 'venue_id' => $this->venue->id,
        'starts_at' => CarbonImmutable::parse('2026-10-21 20:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-10-21 22:00', 'Europe/Berlin'),
    ]);

    $this->actingAs($this->user)->get(handoffUrl($tonight))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('handoffEvent.id', $tonight->id)
            ->where('handoffEvent.place', 'Stadtgarten')
            ->where('handoffEvent.area', 'Belgisches Viertel')
            ->where('handoffEvent.start_time', '20:00')
            ->where('handoffEvent.end_time', '22:00'));

    // Another start of the same event, a hidden event, or one that's over: nothing.
    $this->actingAs($this->user)->get(handoffUrl($tonight, '2026-10-21T19:00:00+02:00'))
        ->assertInertia(fn ($page) => $page->where('handoffEvent', null));

    $hidden = Event::factory()->create(['venue_id' => $this->venue->id, 'status' => 'hidden',
        'starts_at' => CarbonImmutable::parse('2026-10-21 20:00', 'Europe/Berlin')]);
    $this->actingAs($this->user)->get(handoffUrl($hidden))
        ->assertInertia(fn ($page) => $page->where('handoffEvent', null));

    $over = Event::factory()->create(['venue_id' => $this->venue->id,
        'starts_at' => CarbonImmutable::parse('2026-10-21 09:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-10-21 11:00', 'Europe/Berlin')]);
    $this->actingAs($this->user)->get(handoffUrl($over))
        ->assertInertia(fn ($page) => $page->where('handoffEvent', null));

    $this->actingAs($this->user)->get('/timetable?view=journey&event=999999&start=2026-10-21T20:00:00%2B02:00')
        ->assertInertia(fn ($page) => $page->where('handoffEvent', null));
});

test('Events\' link (venue, event id, local day and start) finds the same occurrence, and nothing for any other start', function () {
    $tonight = Event::factory()->create([
        'title' => 'Jazz night', 'venue_id' => $this->venue->id,
        'starts_at' => CarbonImmutable::parse('2026-10-21 20:00', 'Europe/Berlin'),
        'ends_at' => CarbonImmutable::parse('2026-10-21 22:00', 'Europe/Berlin'),
    ]);
    $link = fn (array $query) => '/timetable?'.http_build_query(['to' => 'Stadtgarten', 'context' => $tonight->id, ...$query]);

    $this->actingAs($this->user)->get($link(['date' => '2026-10-21', 'arrive' => '20:00']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('handoffEvent.id', $tonight->id)
            ->where('handoffEvent.place', 'Stadtgarten')
            ->where('handoffEvent.start_time', '20:00'));

    foreach ([['date' => '2026-10-21', 'arrive' => '19:00'], ['date' => '2026-10-22', 'arrive' => '20:00'], ['date' => '21.10.2026', 'arrive' => '20:00'], []] as $other) {
        $this->actingAs($this->user)->get($link($other))
            ->assertInertia(fn ($page) => $page->where('handoffEvent', null));
    }
});
