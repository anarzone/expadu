<?php

use App\Models\CityNews;
use App\Transit\DelfiCancellations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** A protobuf field: tag, then a varint or a length-delimited value. */
function delfiField(int $field, int|string $value): string
{
    $varint = function (int $n): string {
        $out = '';
        do {
            $byte = $n & 0x7F;
            $n >>= 7;
            $out .= chr($n ? $byte | 0x80 : $byte);
        } while ($n);

        return $out;
    };

    return is_int($value)
        ? $varint($field << 3).$varint($value)
        : $varint(($field << 3) | 2).$varint(strlen($value)).$value;
}

/** gtfs.de's realtime feed: trip T1 cancelled today, and an alert on it. */
function delfiFeed(): string
{
    $date = CarbonImmutable::now('Europe/Berlin')->format('Ymd');
    $trip = delfiField(1, 'T1').delfiField(3, $date);
    $cancelled = delfiField(1, 'e1').delfiField(3, delfiField(1, $trip.delfiField(4, 3)));
    $words = fn (string $text) => delfiField(1, delfiField(1, $text).delfiField(2, 'de'));
    $alert = delfiField(1, 'a1').delfiField(5, delfiField(5, delfiField(4, $trip)).delfiField(10, $words('Bau Kappler Straße')).delfiField(11, $words('Linie 17A entfällt während der Bauarbeiten.')));

    return delfiField(2, $cancelled).delfiField(2, $alert);
}

beforeEach(function () {
    config(['services.gtfsde.static_url' => 'https://gtfs.test/static.zip', 'services.gtfsde.realtime_url' => 'https://gtfs.test/realtime.pb']);
    Cache::forget(DelfiCancellations::CANCELLED_KEY);
    $now = CarbonImmutable::now('Europe/Berlin');
    DB::table('delfi_calls')->insert([
        'trip_id' => 'T1', 'stop_sequence' => 1, 'line' => '4', 'headsign' => 'Schlebusch',
        'lat' => 50.9358, 'lng' => 6.9473,
        // GTFS's clock: seconds after the service day's noon minus 12 h; in half an hour.
        'departs' => $now->getTimestamp() - $now->setTime(12, 0)->subHours(12)->getTimestamp() + 1800,
    ]);
    DB::table('delfi_imports')->insert(['id' => 1, 'etag' => '"imported"', 'last_modified' => null, 'imported_at' => now()]);
});

it('reads the feed against the timetable it numbers its trips by', function () {
    Http::fake([
        'gtfs.test/static.zip' => Http::response('', 200, ['ETag' => '"imported"']),
        'gtfs.test/realtime.pb' => Http::response(delfiFeed()),
    ]);

    expect(app(DelfiCancellations::class)->fetch())->toBe(1)
        ->and(CityNews::query()->where('source', 'delfi')->count())->toBe(1);
});

it('keeps no cancellations or notices while the imported timetable is older than the published one', function () {
    Http::fake([
        'gtfs.test/static.zip' => Http::response('', 200, ['ETag' => '"newer"']),
        'gtfs.test/realtime.pb' => Http::response(delfiFeed()),
    ]);

    expect(app(DelfiCancellations::class)->fetch())->toBe(0)
        ->and(json_decode((string) Cache::get(DelfiCancellations::CANCELLED_KEY), true))->toBe([])
        ->and(CityNews::query()->where('source', 'delfi')->count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'realtime.pb'));
});

it('still reads the feed after the cache is lost, since the imported version is kept in the database', function () {
    Http::fake([
        'gtfs.test/static.zip' => Http::response('', 200, ['ETag' => '"imported"']),
        'gtfs.test/realtime.pb' => Http::response(delfiFeed()),
    ]);
    Cache::flush();

    expect(app(DelfiCancellations::class)->fetch())->toBe(1);
    // And the import doesn't fetch a timetable it already has.
    $this->artisan('transit:import-delfi')->expectsOutputToContain('nothing to do')->assertSuccessful();
});

it('takes what a vehicle has for no news', function () {
    expect(DelfiCancellations::notNews('Linie S9: WLAN verfügbar'))->toBeTrue()
        ->and(DelfiCancellations::notNews('Niederflurbus'))->toBeTrue()
        ->and(DelfiCancellations::notNews('Beeinträchtigungen auf der Linie S 9 – Unwetterauswirkungen'))->toBeFalse();
});
