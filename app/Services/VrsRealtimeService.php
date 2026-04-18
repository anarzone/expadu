<?php

namespace App\Services;

use App\Proto\TransitRealtime\FeedMessage;
use App\Proto\TransitRealtime\TripDescriptor\ScheduleRelationship;
use App\Proto\TransitRealtime\TripUpdate\StopTimeUpdate\ScheduleRelationship as StopScheduleRelationship;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches and parses VRS GTFS-RT TripUpdate feed to provide
 * real-time delay and cancellation data for transit departures.
 */
class VrsRealtimeService
{
    /**
     * Get real-time updates indexed by trip_id.
     *
     * @return array<string, array{
     *     trip_id: string,
     *     route_id: string,
     *     cancelled: bool,
     *     stops: array<string, array{delay: int, skipped: bool}>
     * }>
     */
    public function getTripUpdates(): array
    {
        if (! config('services.vrs.enabled')) {
            return [];
        }

        return Cache::remember('vrs_gtfsrt_trip_updates', 30, function () {
            return $this->fetchAndParse();
        });
    }

    /**
     * Get delay info for a specific trip at a specific stop.
     *
     * @return array{delay: int, skipped: bool, cancelled: bool}|null
     */
    public function getDelayForTripAtStop(string $tripId, string $stopId): ?array
    {
        $updates = $this->getTripUpdates();

        if (! isset($updates[$tripId])) {
            return null;
        }

        $trip = $updates[$tripId];

        if ($trip['cancelled']) {
            return ['delay' => 0, 'skipped' => false, 'cancelled' => true];
        }

        if (isset($trip['stops'][$stopId])) {
            $stop = $trip['stops'][$stopId];

            return [
                'delay' => $stop['delay'],
                'skipped' => $stop['skipped'],
                'cancelled' => false,
            ];
        }

        // Trip has updates but not for this specific stop — propagate last known delay
        $lastDelay = $this->propagateDelay($trip, $stopId);

        return $lastDelay !== null
            ? ['delay' => $lastDelay, 'skipped' => false, 'cancelled' => false]
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
     * @return array<string, array{trip_id: string, route_id: string, cancelled: bool, stops: array}>
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
     * @return array<string, array{trip_id: string, route_id: string, cancelled: bool, stops: array}>
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
                'cancelled' => $cancelled,
                'stops' => $stops,
            ];
        }

        // Release protobuf objects to free memory
        unset($feed);

        Log::info('VRS GTFS-RT parsed', ['trip_updates' => count($updates)]);

        return $updates;
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

        // Sort by sequence number and return the last known delay
        $sorted = $trip['stops'];
        uasort($sorted, fn ($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

        $lastDelay = null;
        foreach ($sorted as $stop) {
            if (! $stop['skipped']) {
                $lastDelay = $stop['delay'];
            }
        }

        return $lastDelay;
    }
}
