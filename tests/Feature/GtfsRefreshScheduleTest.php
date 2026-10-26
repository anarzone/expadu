<?php

use App\Console\Commands\ImportGtfsData;
use App\Console\Commands\RefreshGtfs;
use App\Services\VrsRealtimeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Runs gtfs:refresh directly: mocking the Artisan facade would also catch $this->artisan(). */
function runRefresh(array $options = []): array
{
    $command = app(RefreshGtfs::class);
    $command->setLaravel(app());
    $output = new BufferedOutput;

    return [$command->run(new ArrayInput($options), $output), $output->fetch()];
}

function publishVrsFeed(?string $etag, ?string $lastModified = 'Tue, 20 Oct 2026 23:29:37 GMT', int $status = 200): void
{
    Http::fake([ImportGtfsData::FEED_URL => Http::response('', $status, array_filter(['ETag' => $etag, 'Last-Modified' => $lastModified]))]);
}

it('skips the import when VRS has not published a new feed', function () {
    publishVrsFeed('"v1"');
    RefreshGtfs::recordVersion(['etag' => '"v1"', 'last_modified' => null], now());
    Artisan::shouldReceive('call')->never();

    [$code, $output] = runRefresh();
    expect($code)->toBe(0)->and($output)->toContain('nothing to do');
});

it('imports a newly published feed and records its version only after success', function () {
    publishVrsFeed('"v2"');
    RefreshGtfs::recordVersion(['etag' => '"v1"', 'last_modified' => null], Carbon::parse('2026-10-19T03:30:00+02:00'));
    Artisan::shouldReceive('call')->once()->with('gtfs:import', ['--url' => ImportGtfsData::FEED_URL], Mockery::any())->andReturn(0);

    expect(runRefresh()[0])->toBe(0);

    expect(RefreshGtfs::importedVersion()['etag'])->toBe('"v2"');
});

it('keeps the old version when the import fails, so the next run tries again', function () {
    publishVrsFeed('"v2"');
    RefreshGtfs::recordVersion(['etag' => '"v1"', 'last_modified' => null], Carbon::parse('2026-10-19T03:30:00+02:00'));
    Artisan::shouldReceive('call')->once()->andReturn(1);

    expect(runRefresh()[0])->toBe(1);

    expect(RefreshGtfs::importedVersion()['etag'])->toBe('"v1"');
});

it('imports when forced, or when VRS cannot be asked', function (array $options, int $status) {
    publishVrsFeed('"v1"', status: $status);
    RefreshGtfs::recordVersion(['etag' => '"v1"', 'last_modified' => null], now());
    Artisan::shouldReceive('call')->once()->andReturn(0);

    expect(runRefresh($options)[0])->toBe(0);
})->with([
    'forced' => [['--force' => true], 200],
    'HEAD fails' => [[], 503],
]);

it('reports a stale timetable when realtime trips stop matching it', function () {
    publishVrsFeed('"v1"');
    RefreshGtfs::recordVersion(['etag' => '"v1"', 'last_modified' => null], now());
    $trips = collect(range(1, 30))->map(fn ($i) => ['trip_id' => "trip-{$i}-429_X"]);
    DB::table('gtfs_trips')->insert($trips->take(10)->map(fn ($t) => ['trip_id' => $t['trip_id'], 'route_id' => 'r', 'service_id' => 's'])->all());
    $this->mock(VrsRealtimeService::class, fn ($mock) => $mock->shouldReceive('getTripUpdates')->andReturn($trips->keyBy('trip_id')->all()));
    Log::spy();

    $this->artisan('gtfs:health')->expectsOutputToContain('10 of 30')->assertSuccessful();

    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'no longer match the timetable'))->once();
});

it('reports a published timetable left unimported for more than a day and a half', function () {
    publishVrsFeed('"v2"', now()->subHours(40)->toRfc7231String());
    RefreshGtfs::recordVersion(['etag' => '"v1"', 'last_modified' => null], now()->subDays(3));
    $this->mock(VrsRealtimeService::class, fn ($mock) => $mock->shouldReceive('getTripUpdates')->andReturn([]));
    Log::spy();

    $this->artisan('gtfs:health')->assertSuccessful();

    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'has not been imported'))->once();
});

it('stays quiet when the timetable is current and realtime matches', function () {
    publishVrsFeed('"v1"', now()->subHours(40)->toRfc7231String());
    RefreshGtfs::recordVersion(['etag' => '"v1"', 'last_modified' => null], now());
    $trips = collect(range(1, 25))->map(fn ($i) => ['trip_id' => "trip-{$i}"]);
    DB::table('gtfs_trips')->insert($trips->map(fn ($t) => ['trip_id' => $t['trip_id'], 'route_id' => 'r', 'service_id' => 's'])->all());
    $this->mock(VrsRealtimeService::class, fn ($mock) => $mock->shouldReceive('getTripUpdates')->andReturn($trips->keyBy('trip_id')->all()));
    Log::spy();

    $this->artisan('gtfs:health')->assertSuccessful();

    Log::shouldNotHaveReceived('error');
});

it('keeps the imported version when the cache is lost: health still reads it, refresh has nothing to do', function () {
    publishVrsFeed('"v1"', now()->subHours(40)->toRfc7231String());
    RefreshGtfs::recordVersion(['etag' => '"v1"', 'last_modified' => null]);
    Cache::flush();
    $this->mock(VrsRealtimeService::class, fn ($mock) => $mock->shouldReceive('getTripUpdates')->andReturn([]));
    Log::spy();

    $this->artisan('gtfs:health')->assertSuccessful();
    Log::shouldNotHaveReceived('error');

    Artisan::shouldReceive('call')->never();
    [$code, $output] = runRefresh();
    expect($code)->toBe(0)->and($output)->toContain('nothing to do');
});
