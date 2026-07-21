<?php

use App\Models\Event;
use App\Models\Venue;

test('links venue-less events to an existing venue by name and address', function () {
    $venue = Venue::create([
        'name' => 'Museum Ludwig',
        'address_text' => 'Heinrich-Böll-Platz',
        'lat' => 50.9408,
        'lng' => 6.9635,
    ]);
    $event = Event::factory()->create([
        'venue_id' => null,
        'location_name' => 'Museum Ludwig',
        'address' => 'Heinrich-Böll-Platz',
    ]);

    $this->artisan('events:link-venues')->assertSuccessful();

    expect($event->fresh()->venue_id)->toBe($venue->id);
});

test('creates the venue when none exists yet and leaves nameless events alone', function () {
    $orphan = Event::factory()->create([
        'venue_id' => null,
        'location_name' => 'Kolumba – Das Kunstmuseum des Erzbistums Köln',
        'address' => 'Kolumbastraße 4',
    ]);
    $nameless = Event::factory()->create([
        'venue_id' => null,
        'location_name' => null,
    ]);

    $this->artisan('events:link-venues')->assertSuccessful();

    $orphan->refresh();
    expect($orphan->venue_id)->not->toBeNull()
        ->and(Venue::find($orphan->venue_id)?->name)->toBe('Kolumba – Das Kunstmuseum des Erzbistums Köln')
        ->and($nameless->fresh()->venue_id)->toBeNull();
});
