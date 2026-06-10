<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which transit lines are relevant to a user from their saved
 * places (UserPlace): every line serving a stop within walking distance
 * of a place counts, with a human-readable context like "near Home".
 */
class UserTransitLinesService
{
    public function __construct(
        private readonly NearbyStopService $nearbyStopService,
        private readonly GeocodingService $geocodingService,
    ) {}

    /**
     * Get all transit lines relevant to a user.
     *
     * @return array{lines: Collection<int, string>, stops: Collection<int, string>, context: array<string, string>}
     */
    public function getRelevantLines(User $user): array
    {
        // Cache as arrays to avoid __PHP_Incomplete_Class on unserialization in CLI
        $cached = Cache::remember("user_transit_lines:{$user->id}", 600, function () use ($user) {
            $lines = collect();
            $stops = collect();
            $context = [];

            $this->addPlaceLines($user, $lines, $stops, $context);

            return [
                'lines' => $lines->unique()->values()->all(),
                'stops' => $stops->unique()->values()->all(),
                'context' => $context,
            ];
        });

        return [
            'lines' => collect($cached['lines']),
            'stops' => collect($cached['stops']),
            'context' => $cached['context'],
        ];
    }

    /**
     * Lines from saved places with coordinates.
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
     * Get transit line names that serve a given stop via GTFS static data.
     *
     * @return Collection<int, string>
     */
    public function getLinesAtStop(string $stopName): Collection
    {
        // Cache as array to avoid __PHP_Incomplete_Class on unserialization in CLI
        $lines = Cache::remember("lines_at_stop:{$stopName}", 3600, function () use ($stopName) {
            return DB::table('gtfs_stops')
                ->join('gtfs_stop_times', 'gtfs_stops.stop_id', '=', 'gtfs_stop_times.stop_id')
                ->join('gtfs_trips', 'gtfs_stop_times.trip_id', '=', 'gtfs_trips.trip_id')
                ->join('gtfs_routes', 'gtfs_trips.route_id', '=', 'gtfs_routes.route_id')
                ->where('gtfs_stops.stop_name', 'ILIKE', "%{$stopName}%")
                ->where('gtfs_stops.location_type', 0)
                ->whereNotNull('gtfs_routes.route_short_name')
                ->distinct()
                ->pluck('gtfs_routes.route_short_name')
                ->all();
        });

        return collect($lines);
    }
}
