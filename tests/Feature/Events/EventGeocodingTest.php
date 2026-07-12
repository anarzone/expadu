<?php

use App\Models\Event;
use App\Services\EventEnrichmentService;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

function installCompleteEventTestBoundary(): void
{
    foreach (range(1, 86) as $index) {
        DB::table('veedels')->insert([
            'name' => "Test Köln {$index}",
            'bezirk' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    DB::statement(<<<'SQL'
        UPDATE veedels
        SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326))
        SQL);
}

test('event geocoding rejects an out of region Photon result', function () {
    installCompleteEventTestBoundary();
    $geocoder = Mockery::mock(GeocodingService::class);
    $geocoder->shouldReceive('search')
        ->once()
        ->with('Cäcilienstraße 32, Köln')
        ->andReturn([[
            'name' => 'Open Air Kino',
            'street' => 'Olympischer Platz 1',
            'city' => 'Berlin',
            'lat' => 52.5364431,
            'lng' => 13.2015024,
        ]]);
    app()->instance(GeocodingService::class, $geocoder);

    $event = Event::factory()->create([
        'location_name' => 'Open Air Kino Köln',
        'address' => 'Cäcilienstraße 32',
        'needs_review' => false,
    ]);

    app(EventEnrichmentService::class)->enrichEvent($event);

    expect($event->fresh()->lat)->toBeNull()
        ->and($event->fresh()->lng)->toBeNull()
        ->and($event->fresh()->needs_review)->toBeTrue();
});

test('event geocoding prefers the full address and selects a Cologne result', function () {
    installCompleteEventTestBoundary();
    $geocoder = Mockery::mock(GeocodingService::class);
    $geocoder->shouldReceive('search')
        ->once()
        ->with('Cäcilienstraße 32, Köln')
        ->andReturn([
            ['name' => 'Wrong cinema', 'street' => null, 'city' => 'Berlin', 'lat' => 52.5364431, 'lng' => 13.2015024],
            ['name' => 'MAKK', 'street' => 'Cäcilienstraße 29-33', 'city' => 'Köln', 'lat' => 50.9355, 'lng' => 6.9515],
        ]);
    app()->instance(GeocodingService::class, $geocoder);

    $event = Event::factory()->create([
        'location_name' => 'Open-Air-Kino MAKK',
        'address' => 'Cäcilienstraße 32',
    ]);

    app(EventEnrichmentService::class)->enrichEvent($event);

    expect($event->fresh()->lat)->toBe(50.9355)
        ->and($event->fresh()->lng)->toBe(6.9515);
});

test('official polygons reject a nearby result outside the Cologne city boundary', function () {
    installCompleteEventTestBoundary();

    $geocoder = Mockery::mock(GeocodingService::class);
    $geocoder->shouldReceive('search')->once()->andReturn([[
        'name' => 'Nearby but outside',
        'street' => null,
        'city' => 'Hürth',
        'lat' => 50.88,
        'lng' => 6.90,
    ]]);
    app()->instance(GeocodingService::class, $geocoder);

    $event = Event::factory()->create(['address' => 'Nearby border venue', 'needs_review' => false]);
    app(EventEnrichmentService::class)->enrichEvent($event);

    expect($event->fresh()->lat)->toBeNull()
        ->and($event->fresh()->needs_review)->toBeTrue();
});

test('event geocoding failures are logged with structured context', function () {
    installCompleteEventTestBoundary();
    $geocoder = Mockery::mock(GeocodingService::class);
    $geocoder->shouldReceive('search')->once()->andThrow(new RuntimeException('Photon unavailable'));
    app()->instance(GeocodingService::class, $geocoder);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'event geocoding failed'
        && isset($context['event_id'], $context['query'], $context['exception'])
        && $context['error'] === 'Photon unavailable'
    );
    $event = Event::factory()->create(['address' => 'Cäcilienstraße 32']);

    app(EventEnrichmentService::class)->enrichEvent($event);
});

test('event geocoding fails closed while official polygons are incomplete', function () {
    $geocoder = Mockery::mock(GeocodingService::class);
    $geocoder->shouldReceive('search')->once()->andReturn([[
        'name' => 'MAKK', 'street' => 'Cäcilienstraße', 'city' => 'Köln',
        'lat' => 50.9355, 'lng' => 6.9515,
    ]]);
    app()->instance(GeocodingService::class, $geocoder);
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'event geocoding failed'
        && str_contains($context['error'], '86 official Cologne polygons')
    );
    $event = Event::factory()->create(['address' => 'Cäcilienstraße 32']);

    app(EventEnrichmentService::class)->enrichEvent($event);

    expect($event->fresh()->lat)->toBeNull();
});
