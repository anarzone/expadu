<?php

use App\Models\Event;
use App\Models\Venue;
use App\Services\CologneServiceArea;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\DB;

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

test('a coordinate-less venue borrows trusted coordinates from its own events', function () {
    $venue = Venue::create([
        'name' => 'Flora und Botanischer Garten',
        'address_text' => 'Am Botanischen Garten 1a',
        'lat' => null,
        'lng' => null,
    ]);
    $event = Event::factory()->create(['venue_id' => $venue->id, 'location_name' => 'Flora und Botanischer Garten']);
    DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [6.9731, 50.9585, $event->id],
    );

    $this->artisan('events:link-venues')->assertSuccessful();

    $venue->refresh();
    expect((float) $venue->lat)->toEqualWithDelta(50.9585, 0.0001)
        ->and((float) $venue->lng)->toEqualWithDelta(6.9731, 0.0001);
});

test('a venue with no coordinate source anywhere gets geocoded, Cologne-gated', function () {
    $inCologne = Venue::create(['name' => 'Kulturkirche Ost', 'address_text' => 'Kopernikusstraße 34', 'lat' => null, 'lng' => null]);
    $elsewhere = Venue::create(['name' => 'Ambiguous Halle', 'address_text' => null, 'lat' => null, 'lng' => null]);

    // The test DB has no official boundary polygons — gate on a bounding box.
    $area = Mockery::mock(CologneServiceArea::class);
    $area->shouldReceive('contains')->andReturnUsing(fn (float $lat, float $lng): bool => $lat > 50.8 && $lat < 51.1 && $lng > 6.7 && $lng < 7.2);
    app()->instance(CologneServiceArea::class, $area);

    // Pest.php globally stubs photon with empty features (offline tests), so
    // stub the geocoder itself: the address resolves in Cologne, the
    // address-less name resolves to Berlin — which the area gate must reject.
    $geocoder = Mockery::mock(GeocodingService::class);
    $geocoder->shouldReceive('search')->andReturnUsing(fn (string $query): array => str_contains($query, 'Kopernikus')
        ? [['name' => 'Kopernikusstraße 34', 'street' => null, 'city' => 'Köln', 'lat' => 50.9457, 'lng' => 7.0016]]
        : [['name' => 'Ambiguous Halle', 'street' => null, 'city' => 'Berlin', 'lat' => 52.52, 'lng' => 13.4]]);
    app()->instance(GeocodingService::class, $geocoder);

    $this->artisan('events:link-venues')->assertSuccessful();

    expect((float) $inCologne->fresh()->lat)->toEqualWithDelta(50.9457, 0.001)
        ->and($elsewhere->fresh()->lat)->toBeNull();
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
