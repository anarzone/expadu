<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Resolves which transit lines are relevant to a user by merging three sources:
 * 1. Active routines (from_stop / to_stop)
 * 2. Saved places with coordinates (UserPlace)
 * 3. Current GPS location if stationary for 20+ minutes
 */
class UserTransitLinesService
{
    public function __construct(
        private readonly NearbyStopService $nearbyStopService,
        private readonly GeocodingService $geocodingService,
    ) {}

    /**
     * Get all transit lines relevant to a user from all sources.
     *
     * @return array{lines: Collection<int, string>, stops: Collection<int, string>, context: array<string, string>}
     */
    public function getRelevantLines(User $user): array
    {
        return Cache::remember("user_transit_lines:{$user->id}", 600, function () use ($user) {
            $lines = collect();
            $stops = collect();
            $context = [];

            $this->addRoutineLines($user, $lines, $stops, $context);
            $this->addPlaceLines($user, $lines, $stops, $context);
            $this->addGpsLines($user, $lines, $stops, $context);

            return [
                'lines' => $lines->unique()->values(),
                'stops' => $stops->unique()->values(),
                'context' => $context,
            ];
        });
    }

    /**
     * Source 1: Lines from active routines (existing behavior).
     */
    private function addRoutineLines(User $user, Collection $lines, Collection $stops, array &$context): void
    {
        $routines = $user->routines()->where('is_active', true)->get();

        foreach ($routines as $routine) {
            $fromLines = $this->getLinesAtStop($routine->from_stop);
            $toLines = $routine->to_stop ? $this->getLinesAtStop($routine->to_stop) : collect();

            foreach ($fromLines->merge($toLines)->unique() as $line) {
                $lines->push($line);
                $context[$line] ??= "on your {$routine->name} commute";
            }

            $stops->push($routine->from_stop);
            if ($routine->to_stop) {
                $stops->push($routine->to_stop);
            }
        }
    }

    /**
     * Source 2: Lines from saved places with coordinates.
     */
    private function addPlaceLines(User $user, Collection $lines, Collection $stops, array &$context): void
    {
        $places = $user->places()->get();

        foreach ($places as $place) {
            // Auto-geocode if address exists but no coordinates
            if (! $place->lat && $place->address) {
                $results = $this->geocodingService->search($place->address);
                if (! empty($results)) {
                    $place->update(['lat' => $results[0]['lat'], 'lng' => $results[0]['lng']]);
                }
            }

            if (! $place->lat || ! $place->lng) {
                continue;
            }

            $nearbyStops = $this->nearbyStopService->getWalkableStops(
                (float) $place->lat,
                (float) $place->lng,
                500
            );

            foreach ($nearbyStops as $stop) {
                $stops->push($stop['name']);
                foreach ($stop['lines'] as $line) {
                    $lines->push($line);
                    $context[$line] ??= "near {$place->name}";
                }
            }
        }
    }

    /**
     * Source 3: Lines near current GPS location if stationary for 20+ minutes.
     */
    private function addGpsLines(User $user, Collection $lines, Collection $stops, array &$context): void
    {
        $location = $this->getStationaryLocation($user);

        if (! $location) {
            return;
        }

        $nearbyStops = $this->nearbyStopService->getWalkableStops(
            $location['lat'],
            $location['lng'],
            500
        );

        foreach ($nearbyStops as $stop) {
            $stops->push($stop['name']);
            foreach ($stop['lines'] as $line) {
                $lines->push($line);
                $context[$line] ??= 'near your current location';
            }
        }
    }

    /**
     * Detect if user has been stationary for the given number of minutes.
     * Returns centroid coordinates or null if moving/insufficient data.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function getStationaryLocation(User $user, int $minutes = 20): ?array
    {
        try {
            $key = "location_history:{$user->id}";
            $since = now()->subMinutes($minutes)->timestamp;
            $entries = Redis::zrangebyscore($key, $since, '+inf');

            if (count($entries) < 2) {
                return null;
            }

            $points = [];
            foreach ($entries as $entry) {
                $data = json_decode($entry, true);
                if (! $data || ($data['rejected'] ?? false)) {
                    continue;
                }
                if (isset($data['lat'], $data['lng'])) {
                    $points[] = ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']];
                }
            }

            if (count($points) < 2) {
                return null;
            }

            // Compute centroid
            $centroidLat = collect($points)->avg('lat');
            $centroidLng = collect($points)->avg('lng');

            // Check max spread from centroid
            $maxSpread = 0;
            foreach ($points as $p) {
                $dist = $this->haversineMeters($centroidLat, $centroidLng, $p['lat'], $p['lng']);
                $maxSpread = max($maxSpread, $dist);
            }

            // If any ping is >100m from centroid, user is moving
            if ($maxSpread > 100) {
                return null;
            }

            return ['lat' => round($centroidLat, 6), 'lng' => round($centroidLng, 6)];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get transit line names that serve a given stop via GTFS static data.
     *
     * @return Collection<int, string>
     */
    public function getLinesAtStop(string $stopName): Collection
    {
        return Cache::remember("lines_at_stop:{$stopName}", 3600, function () use ($stopName) {
            return DB::table('gtfs_stops')
                ->join('gtfs_stop_times', 'gtfs_stops.stop_id', '=', 'gtfs_stop_times.stop_id')
                ->join('gtfs_trips', 'gtfs_stop_times.trip_id', '=', 'gtfs_trips.trip_id')
                ->join('gtfs_routes', 'gtfs_trips.route_id', '=', 'gtfs_routes.route_id')
                ->where('gtfs_stops.stop_name', 'ILIKE', "%{$stopName}%")
                ->where('gtfs_stops.location_type', 0)
                ->whereNotNull('gtfs_routes.route_short_name')
                ->distinct()
                ->pluck('gtfs_routes.route_short_name');
        });
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
