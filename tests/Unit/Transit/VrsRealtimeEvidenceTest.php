<?php

use App\Proto\TransitRealtime\FeedEntity;
use App\Proto\TransitRealtime\FeedHeader;
use App\Proto\TransitRealtime\FeedMessage;
use App\Proto\TransitRealtime\TripDescriptor;
use App\Proto\TransitRealtime\TripDescriptor\ScheduleRelationship;
use App\Proto\TransitRealtime\TripUpdate;
use App\Proto\TransitRealtime\TripUpdate\StopTimeEvent;
use App\Proto\TransitRealtime\TripUpdate\StopTimeUpdate;
use App\Services\VrsRealtimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array', 'services.vrs.enabled' => true, 'services.vrs.gtfsrt_url' => 'https://realtime.test']);
    Cache::flush();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-06T12:00:00Z'));
});

function realtimeEvidenceFeed(?int $tripTimestamp, ?int $headerTimestamp, bool $cancelled = false): string
{
    $trip = new TripUpdate([
        'trip' => new TripDescriptor([
            'trip_id' => 'trip:1',
            'route_id' => 'route:1',
            'schedule_relationship' => $cancelled ? ScheduleRelationship::CANCELED : ScheduleRelationship::SCHEDULED,
        ]),
        'stop_time_update' => [new StopTimeUpdate([
            'stop_id' => 'stop:1',
            'stop_sequence' => 1,
            'departure' => new StopTimeEvent(['delay' => 120]),
        ])],
    ]);
    $header = new FeedHeader(['gtfs_realtime_version' => '2.0']);

    if ($tripTimestamp !== null) {
        $trip->setTimestamp($tripTimestamp);
    }

    if ($headerTimestamp !== null) {
        $header->setTimestamp($headerTimestamp);
    }

    return (new FeedMessage([
        'header' => $header,
        'entity' => [new FeedEntity(['id' => 'entity:1', 'trip_update' => $trip])],
    ]))->serializeToString();
}

test('trip observations retain their own time instead of the newer feed or request time', function () {
    $observed = now()->subMinutes(10);
    Http::fake(['realtime.test' => Http::response(realtimeEvidenceFeed($observed->timestamp, now()->timestamp))]);
    $service = app(VrsRealtimeService::class);

    expect($service->getTripUpdates()['trip:1']['observed_at'])->toBe($observed->copy()->utc()->toIso8601String())
        ->and($service->getDelayForTripAtStop('trip:1', 'stop:1'))->toBe([
            'delay' => 120, 'skipped' => false, 'cancelled' => false, 'observed_at' => $observed->copy()->utc()->toIso8601String(),
        ]);
});

test('missing trip observation time falls back to the feed header', function () {
    $observed = now()->subSeconds(40);
    Http::fake(['realtime.test' => Http::response(realtimeEvidenceFeed(null, $observed->timestamp))]);

    expect(app(VrsRealtimeService::class)->getTripUpdates()['trip:1']['observed_at'])->toBe($observed->copy()->utc()->toIso8601String());
});

test('undated or future observations remain unknown without inventing a capture time', function (?int $tripOffset, ?int $headerOffset) {
    $timestamp = fn (?int $offset): ?int => $offset === null ? null : now()->timestamp + $offset;
    Http::fake(['realtime.test' => Http::response(realtimeEvidenceFeed($timestamp($tripOffset), $timestamp($headerOffset)))]);

    expect(app(VrsRealtimeService::class)->getTripUpdates()['trip:1']['observed_at'])->toBeNull();
})->with([
    'both missing' => [null, null],
    'future trip with valid header' => [1, -10],
    'future header fallback' => [null, 1],
]);

test('cancelled trips preserve the observation time in stop status', function () {
    $observed = now()->subMinute();
    Http::fake(['realtime.test' => Http::response(realtimeEvidenceFeed($observed->timestamp, null, true))]);

    expect(app(VrsRealtimeService::class)->getDelayForTripAtStop('trip:1', 'stop:1'))->toBe([
        'delay' => 0, 'skipped' => false, 'cancelled' => true, 'observed_at' => $observed->copy()->utc()->toIso8601String(),
    ]);
});

test('cached observations keep their age and older cache formats are ignored', function () {
    Cache::put('vrs_gtfsrt_trip_updates', ['legacy' => ['trip_id' => 'legacy']], 30);
    $observed = now()->subMinutes(5);
    Http::fake(['realtime.test' => Http::response(realtimeEvidenceFeed($observed->timestamp, null))]);
    $service = app(VrsRealtimeService::class);
    $first = $service->getTripUpdates();
    $this->travel(20)->seconds();

    expect($first)->not->toHaveKey('legacy')
        ->and($service->getTripUpdates())->toBe($first)
        ->and($first['trip:1']['observed_at'])->toBe($observed->copy()->utc()->toIso8601String());
    Http::assertSentCount(1);
});

test('trip service dates are preserved for overnight estimate matching', function () {
    $feed = new FeedMessage;
    $feed->mergeFromString(realtimeEvidenceFeed(now()->timestamp, null));
    $feed->getEntity()[0]->getTripUpdate()->getTrip()->setStartDate('20261005');
    Http::fake(['realtime.test' => Http::response($feed->serializeToString())]);
    expect(app(VrsRealtimeService::class)->getTripUpdates()['trip:1']['service_date'])->toBe('2026-10-05');
});
