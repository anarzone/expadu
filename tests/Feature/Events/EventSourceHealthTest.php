<?php

use App\Models\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

test('source health reports fresh official inventory as structured JSON', function () {
    Event::factory()->create([
        'source' => 'stadt-koeln.de',
        'source_uid' => 'fresh',
        'starts_at' => now()->addDay(),
        'verified_at' => now()->subHour(),
        'title_en' => 'Translated', 'description_en' => 'Translated description',
    ]);
    $event = Event::where('source_uid', 'fresh')->sole();
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(6.95, 50.94), 4326)::geography WHERE id = ?', [$event->id]);
    Cache::put('events:source-run:stadt-koeln.de', ['status' => 'succeeded', 'completed_at' => now()->toIso8601String(), 'fetched' => 1, 'imported' => 1]);

    $this->artisan('events:source-health --json')
        ->expectsOutputToContain('"source":"stadt-koeln.de","status":"healthy"')
        ->assertSuccessful();
});

test('source health fails when the official feed has gone stale', function () {
    Event::factory()->create([
        'source' => 'stadt-koeln.de',
        'source_uid' => 'stale',
        'starts_at' => now()->addDay(),
        'verified_at' => now()->subHours(49),
    ]);
    Cache::put('events:source-run:stadt-koeln.de', ['status' => 'succeeded', 'completed_at' => now()->subHours(49)->toIso8601String(), 'fetched' => 1, 'imported' => 1]);

    $this->artisan('events:source-health --json --max-age=36')
        ->expectsOutputToContain('"status":"stale"')
        ->assertFailed();
});
