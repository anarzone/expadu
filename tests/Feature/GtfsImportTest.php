<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Build a minimal GTFS ZIP in memory for testing
    $this->zipPath = storage_path('app/temp/test_gtfs.zip');
    @mkdir(storage_path('app/temp'), 0755, true);

    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addFromString('stops.txt', implode("\n", [
        'stop_id,stop_name,stop_lat,stop_lon,location_type,parent_station',
        'de:05315:11001,Neumarkt,50.9352768,6.9437920,1,',
        'de:05315:11001:1,Neumarkt Gl.1,50.9353000,6.9438000,0,de:05315:11001',
    ]));

    $zip->addFromString('routes.txt', implode("\n", [
        'route_id,route_short_name,route_long_name,route_type,route_color',
        'vrs:11009:E,9,Linie 9,0,E3000B',
    ]));

    $zip->addFromString('trips.txt', implode("\n", [
        'trip_id,route_id,service_id,trip_headsign,direction_id',
        'trip_001,vrs:11009:E,weekday,Königsforst,0',
    ]));

    $zip->addFromString('stop_times.txt', implode("\n", [
        'trip_id,stop_id,arrival_time,departure_time,stop_sequence',
        'trip_001,de:05315:11001:1,08:15:00,08:15:00,1',
        'trip_001,de:05315:11001:1,08:22:00,08:22:00,2',
    ]));

    $zip->close();
});

afterEach(function () {
    @unlink($this->zipPath);
});

test('gtfs:import command imports data from a zip file', function () {
    // Fake the HTTP download to return our test zip
    Http::fake([
        'example.com/test.zip' => Http::response(file_get_contents($this->zipPath)),
        'api.brightsky.dev/*' => Http::response(['weather' => ['temperature' => 15.0]]),
        'photon.komoot.io/*' => Http::response(['features' => []]),
    ]);

    $this->artisan('gtfs:import', ['--url' => 'https://example.com/test.zip'])
        ->assertSuccessful();

    expect(DB::table('gtfs_stops')->count())->toBe(2);
    expect(DB::table('gtfs_routes')->count())->toBe(1);
    expect(DB::table('gtfs_trips')->count())->toBe(1);
    expect(DB::table('gtfs_stop_times')->count())->toBe(2);

    // Verify data content
    $stop = DB::table('gtfs_stops')->where('stop_id', 'de:05315:11001')->first();
    expect($stop->stop_name)->toBe('Neumarkt');
    expect((float) $stop->stop_lat)->toBe(50.9352768);
    expect($stop->location_type)->toBe(1);

    $route = DB::table('gtfs_routes')->first();
    expect($route->route_short_name)->toBe('9');
    expect($route->route_color)->toBe('E3000B');
});

test('gtfs:import truncates existing data before import', function () {
    // Insert existing data
    DB::table('gtfs_stops')->insert([
        'stop_id' => 'old_stop',
        'stop_name' => 'Old Stop',
        'stop_lat' => 50.0,
        'stop_lng' => 6.0,
    ]);

    Http::fake([
        'example.com/test.zip' => Http::response(file_get_contents($this->zipPath)),
        'api.brightsky.dev/*' => Http::response(['weather' => ['temperature' => 15.0]]),
        'photon.komoot.io/*' => Http::response(['features' => []]),
    ]);

    $this->artisan('gtfs:import', ['--url' => 'https://example.com/test.zip'])
        ->assertSuccessful();

    // Old data should be gone
    expect(DB::table('gtfs_stops')->where('stop_id', 'old_stop')->exists())->toBeFalse();
    expect(DB::table('gtfs_stops')->count())->toBe(2);
});
