<?php

namespace App\Services;

use App\Models\Gtfs\GtfsStopTime;
use App\Proto\TransitRealtime\FeedMessage;
use App\Proto\TransitRealtime\TripDescriptor\ScheduleRelationship;
use App\Proto\TransitRealtime\TripUpdate\StopTimeUpdate\ScheduleRelationship as StopScheduleRelationship;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches and parses VRS GTFS-RT TripUpdate feed to provide
 * real-time delay and cancellation data for transit departures.
 */
class VrsRealtimeService
{
    private const FEED_KEY = 'vrs_gtfsrt_trip_updates_v2';

    /** Seconds a fetched feed is kept, here and in the cache. */
    private const FEED_SECONDS = 30;

    /** @var array<string, array<string, mixed>>|null the feed, as last read */
    private ?array $feed = null;

    private int $feedReadAt = 0;

    /**
     * Get real-time updates indexed by trip_id.
     *
     * @return array<string, array{
     *     trip_id: string,
     *     route_id: string,
     *     cancelled: bool,
     *     observed_at: string|null,
     *     stops: array<string, array{delay: int, skipped: bool}>
     * }>
     */
    public function getTripUpdates(): array
    {
        if (! config('services.vrs.enabled')) {
            return [];
        }

        // It's consulted per departure row, so it's kept here once read (the
        // cache's memo kept a miss, and every row then took the fetch lock and
        // unserialized the whole feed again). When it has expired, one worker
        // refetches; the others wait for its copy.
        if ($this->feed !== null && now()->timestamp - $this->feedReadAt < self::FEED_SECONDS) {
            return $this->feed;
        }

        $updates = Cache::get(self::FEED_KEY);
        if (! is_array($updates)) {
            $lock = Cache::lock('vrs_gtfsrt:fetch', 15);
            try {
                self::onRealClock(fn () => $lock->block((int) config('services.vrs.gtfsrt_lock_wait', 10)));
                try {
                    $updates = Cache::remember(self::FEED_KEY, self::FEED_SECONDS, fn () => $this->fetchAndParse());
                } finally {
                    $lock->release();
                }
            } catch (LockTimeoutException) {
                // Another worker's fetch (or a stale lock) held it the whole
                // wait: this request goes without live times, and waits once,
                // not once per departure row.
                $updates = [];
            }
        }

        $this->feed = $updates;
        $this->feedReadAt = now()->timestamp;

        return $updates;
    }

    /**
     * Runs with the real clock: a lock taken under a faked one (the mock
     * clock, composer:eval's day) expired days ahead and held every fetch.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function onRealClock(callable $callback): mixed
    {
        $faked = [Carbon::getTestNow(), CarbonImmutable::getTestNow()];
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        try {
            return $callback();
        } finally {
            Carbon::setTestNow($faked[0]);
            CarbonImmutable::setTestNow($faked[1]);
        }
    }

    /**
     * Get delay info for a specific trip at a specific stop.
     *
     * @return array{delay: int, skipped: bool, cancelled: bool, observed_at: string|null}|null
     */
    public function getDelayForTripAtStop(string $tripId, string $stopId): ?array
    {
        $updates = $this->getTripUpdates();

        if (! isset($updates[$tripId])) {
            return null;
        }

        $trip = $updates[$tripId];
        $observedAt = $trip['observed_at'] ?? null;

        if ($trip['cancelled']) {
            return ['delay' => 0, 'skipped' => false, 'cancelled' => true, 'observed_at' => $observedAt];
        }

        if (isset($trip['stops'][$stopId])) {
            $stop = $trip['stops'][$stopId];

            return [
                'delay' => $stop['delay'],
                'skipped' => $stop['skipped'],
                'cancelled' => false,
                'observed_at' => $observedAt,
            ];
        }

        // Trip has updates but not for this specific stop — propagate last known delay
        $lastDelay = $this->propagateDelay($trip, $stopId);

        return $lastDelay !== null
            ? ['delay' => $lastDelay, 'skipped' => false, 'cancelled' => false, 'observed_at' => $observedAt]
            : null;
    }

    /**
     * Get delay info for a specific trip (general, not stop-specific).
     * Returns the maximum delay across all stop updates.
     *
     * @return array{delay: int, cancelled: bool}|null
     */
    public function getDelayForTrip(string $tripId): ?array
    {
        $updates = $this->getTripUpdates();

        if (! isset($updates[$tripId])) {
            return null;
        }

        $trip = $updates[$tripId];

        if ($trip['cancelled']) {
            return ['delay' => 0, 'cancelled' => true];
        }

        // Use the first stop_time_update's departure delay as representative
        $maxDelay = 0;
        foreach ($trip['stops'] as $stop) {
            if (! $stop['skipped'] && abs($stop['delay']) > abs($maxDelay)) {
                $maxDelay = $stop['delay'];
            }
        }

        return ['delay' => $maxDelay, 'cancelled' => false];
    }

    /**
     * Fetch the GTFS-RT binary feed from VRS and parse it.
     *
     * @return array<string, array{trip_id: string, route_id: string, cancelled: bool, observed_at: string|null, stops: array}>
     */
    private function fetchAndParse(): array
    {
        try {
            $url = config('services.vrs.gtfsrt_url');

            $options = [
                'timeout' => 3,
                'connect_timeout' => 2,
            ];

            $this->applyVrsSsl($options);

            $response = Http::withOptions($options)->get($url);

            if (! $response->successful()) {
                Log::warning('VRS GTFS-RT fetch failed', [
                    'status' => $response->status(),
                    'url' => $url,
                ]);

                return [];
            }

            return $this->parseProtobuf($response->body());
        } catch (\Throwable $e) {
            Log::warning('VRS GTFS-RT error', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse binary protobuf GTFS-RT FeedMessage into structured array.
     *
     * @return array<string, array{trip_id: string, route_id: string, cancelled: bool, observed_at: string|null, stops: array}>
     */
    private function parseProtobuf(string $binaryData): array
    {
        $feed = new FeedMessage;
        $feed->mergeFromString($binaryData);

        $updates = [];
        $entityCount = $feed->getEntity()->count();

        for ($i = 0; $i < $entityCount; $i++) {
            $entity = $feed->getEntity()[$i];
            $tripUpdate = $entity->getTripUpdate();
            if (! $tripUpdate) {
                continue;
            }

            $trip = $tripUpdate->getTrip();
            if (! $trip) {
                continue;
            }

            $tripId = $trip->getTripId();
            if (! $tripId) {
                continue;
            }

            $cancelled = $trip->getScheduleRelationship() === ScheduleRelationship::CANCELED;
            $timestamp = $tripUpdate->getTimestamp();

            // Zero is protobuf's default when the optional trip timestamp is absent.
            if ($timestamp === 0 || $timestamp === '0') {
                $timestamp = $feed->getHeader()?->getTimestamp();
            }

            $stops = [];
            foreach ($tripUpdate->getStopTimeUpdate() as $stu) {
                $stopId = $stu->getStopId();
                if (! $stopId) {
                    continue;
                }

                $skipped = $stu->getScheduleRelationship() === StopScheduleRelationship::SKIPPED;

                // Prefer departure delay, fall back to arrival delay
                $delay = 0;
                if ($stu->getDeparture() && $stu->getDeparture()->getDelay() !== 0) {
                    $delay = $stu->getDeparture()->getDelay();
                } elseif ($stu->getArrival() && $stu->getArrival()->getDelay() !== 0) {
                    $delay = $stu->getArrival()->getDelay();
                }

                $stops[$stopId] = [
                    'delay' => $delay,
                    'skipped' => $skipped,
                    'sequence' => $stu->getStopSequence(),
                ];
            }

            $updates[$tripId] = [
                'trip_id' => $tripId,
                'route_id' => $trip->getRouteId() ?: '',
                'service_date' => $this->serviceDate($trip->getStartDate()),
                'cancelled' => $cancelled,
                'observed_at' => $this->observationTime($timestamp),
                'stops' => $stops,
            ];
        }

        // Release protobuf objects to free memory
        unset($feed);

        Log::info('VRS GTFS-RT parsed', ['trip_updates' => count($updates)]);

        return $updates;
    }

    private function serviceDate(string $date): ?string
    {
        if (! preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/D', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $parts[1].'-'.$parts[2].'-'.$parts[3];
    }

    private function observationTime(int|string|null $timestamp): ?string
    {
        $validated = filter_var($timestamp, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => now()->timestamp],
        ]);

        if ($validated === false) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($validated)->toIso8601String();
    }

    /**
     * GTFS-RT spec says: if a stop has no StopTimeUpdate, the delay from the
     * previous stop propagates forward. Find the most recent update before
     * the requested stop's position in the trip.
     */
    /**
     * Apply VRS client certificate to Guzzle options for mTLS authentication.
     */
    private function applyVrsSsl(array &$options): void
    {
        $clientCert = config('services.vrs.client_cert');
        $clientCertPassword = config('services.vrs.client_cert_password');

        if ($clientCert && file_exists($clientCert)) {
            $options['cert'] = $clientCertPassword
                ? [$clientCert, $clientCertPassword]
                : $clientCert;
        }
    }

    private function propagateDelay(array $trip, string $targetStopId): ?int
    {
        if (empty($trip['stops'])) {
            return null;
        }

        $targetSequence = GtfsStopTime::query()
            ->where('trip_id', $trip['trip_id'])
            ->where('stop_id', $targetStopId)
            ->value('stop_sequence');

        if ($targetSequence === null) {
            return null;
        }

        // Propagate only from the most recent update at or before the target.
        // A later stop's delay must never be applied backwards along the trip.
        $sorted = $trip['stops'];
        uasort($sorted, fn ($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

        $lastDelay = null;
        foreach ($sorted as $stop) {
            if (($stop['sequence'] ?? 0) > $targetSequence) {
                break;
            }

            if (! $stop['skipped']) {
                $lastDelay = $stop['delay'];
            }
        }

        return $lastDelay;
    }
}
