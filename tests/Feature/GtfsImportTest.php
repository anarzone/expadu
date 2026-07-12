<?php

use App\Console\Commands\RefreshGtfs;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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

    $zip->addFromString('calendar.txt', implode("\n", [
        'service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date',
        'weekday,1,1,1,1,1,0,0,20260101,20261231',
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

test('gtfs:import rolls back every timetable table when an import fails', function () {
    DB::table('gtfs_stops')->insert([
        'stop_id' => 'old_stop',
        'stop_name' => 'Old Stop',
        'stop_lat' => 50.94,
        'stop_lng' => 6.96,
    ]);
    DB::table('gtfs_routes')->insert(['route_id' => 'old_route', 'route_short_name' => 'OLD', 'route_type' => 3]);
    DB::table('gtfs_trips')->insert(['trip_id' => 'old_trip', 'route_id' => 'old_route', 'service_id' => 'old_service']);
    DB::table('gtfs_stop_times')->insert([
        'trip_id' => 'old_trip',
        'stop_id' => 'old_stop',
        'arrival_time' => '10:00:00',
        'departure_time' => '10:00:00',
        'stop_sequence' => 1,
    ]);

    $brokenZip = storage_path('app/temp/test_gtfs_broken.zip');
    $zip = new ZipArchive;
    $zip->open($brokenZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('stops.txt', "stop_id,stop_name,stop_lat,stop_lon,location_type,parent_station\nnew_stop,New Stop,50.95,6.97,0,\n");
    $zip->addFromString('routes.txt', "route_id,route_short_name,route_long_name,route_type,route_color\nnew_route,NEW,New Route,3,000000\n");
    $zip->addFromString('trips.txt', "trip_id,route_id,service_id,trip_headsign,direction_id\nnew_trip,new_route,new_service,New Head,0\n");
    $zip->addFromString('stop_times.txt', "trip_id,stop_id,arrival_time,departure_time,stop_sequence\nnew_trip,new_stop,11:00:00,11:00:00,70000\n");
    $zip->addFromString('calendar.txt', "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nnew_service,1,1,1,1,1,0,0,20260101,20261231\n");
    $zip->close();

    Http::fake(['example.com/broken.zip' => Http::response(file_get_contents($brokenZip))]);

    $this->artisan('gtfs:import', ['--url' => 'https://example.com/broken.zip'])
        ->assertFailed();

    expect(DB::table('gtfs_stops')->where('stop_id', 'old_stop')->exists())->toBeTrue()
        ->and(DB::table('gtfs_routes')->where('route_id', 'old_route')->exists())->toBeTrue()
        ->and(DB::table('gtfs_trips')->where('trip_id', 'old_trip')->exists())->toBeTrue()
        ->and(DB::table('gtfs_stop_times')->where('trip_id', 'old_trip')->exists())->toBeTrue();

    @unlink($brokenZip);
});

test('gtfs:refresh keeps departure caches when the import fails', function () {
    Artisan::shouldReceive('call')->once()->with('gtfs:import', [], Mockery::any())->andReturn(1);
    Cache::shouldReceive('forget')->never();
    Cache::shouldReceive('flush')->never();

    $command = app(RefreshGtfs::class);
    $command->setLaravel(app());

    expect($command->run(new ArrayInput([]), new NullOutput))->toBe(1);
});

test('gtfs:import rejects a feed without any service calendar source', function () {
    $withoutCalendar = storage_path('app/temp/test_gtfs_without_calendar.zip');
    $zip = new ZipArchive;
    $zip->open($withoutCalendar, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (['stops.txt', 'routes.txt', 'trips.txt', 'stop_times.txt'] as $filename) {
        $source = new ZipArchive;
        $source->open($this->zipPath);
        $zip->addFromString($filename, $source->getFromName($filename));
        $source->close();
    }
    $zip->close();

    Http::fake(['example.com/no-calendar.zip' => Http::response(file_get_contents($withoutCalendar))]);

    $this->artisan('gtfs:import', ['--url' => 'https://example.com/no-calendar.zip'])
        ->expectsOutputToContain('calendar source')
        ->assertFailed();

    @unlink($withoutCalendar);
});
