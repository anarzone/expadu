<?php

use App\Models\Event;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

function installCompleteAuditTestBoundary(): void
{
    foreach (range(1, 86) as $index) {
        DB::table('veedels')->insert([
            'name' => "Audit Köln {$index}", 'bezirk' => 'Test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::statement("UPDATE veedels SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326))");
}

test('coordinate audit reports invalid rows without changing them by default', function () {
    installCompleteAuditTestBoundary();
    $event = Event::factory()->create(['needs_review' => false]);
    DB::statement(
        'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
        [13.2015024, 52.5364431, $event->id],
    );

    $this->artisan('events:audit-coordinates')
        ->expectsOutputToContain('1 event(s) outside')
        ->assertSuccessful();

    expect($event->fresh()->lat)->toBe(52.5364431)
        ->and($event->fresh()->needs_review)->toBeFalse();
});

test('coordinate audit can clear invalid rows and quarantine them for review', function () {
    installCompleteAuditTestBoundary();
    $venue = Venue::query()->create([
        'name' => 'Wrong Berlin venue',
        'lat' => 52.5364431,
        'lng' => 13.2015024,
    ]);
    $invalid = Event::factory()->create(['needs_review' => false, 'venue_id' => $venue->id]);
    $valid = Event::factory()->create(['needs_review' => false]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [13.2015024, 52.5364431, $invalid->id]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [6.9603, 50.9375, $valid->id]);

    $this->artisan('events:audit-coordinates --reset')
        ->expectsOutputToContain('Reset 1 invalid event coordinate(s)')
        ->assertSuccessful();

    expect($invalid->fresh()->lat)->toBeNull()
        ->and($invalid->fresh()->venue_id)->toBeNull()
        ->and($invalid->fresh()->needs_review)->toBeTrue()
        ->and($valid->fresh()->lat)->toBe(50.9375)
        ->and($valid->fresh()->needs_review)->toBeFalse();
});

test('coordinate audit fails loudly when official polygons are unavailable', function () {
    $this->artisan('events:audit-coordinates')
        ->expectsOutputToContain('86 official Cologne polygons')
        ->assertFailed();
});
