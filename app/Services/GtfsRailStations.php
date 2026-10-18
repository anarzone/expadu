<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every rail (route_type 2 — S-Bahn and regional) platform in the loaded GTFS
 * feed. Finding them scans the stop_times/trips/routes join, so the list is
 * built once and kept until the next import (gtfs:refresh forgets it);
 * callers then filter by distance in memory.
 */
class GtfsRailStations
{
    public const CACHE_KEY = 'gtfs:rail-stations:v1';

    /**
     * @return list<array{name: string, lat: float, lng: float}>
     */
    public function all(): array
    {
        return Cache::memo()->remember(self::CACHE_KEY, now()->addDays(30), function (): array {
            return DB::table('gtfs_stops')
                ->whereNotNull('stop_lat')
                ->whereNotNull('stop_lng')
                ->where('location_type', 0)
                ->whereIn('stop_id', function ($query) {
                    $query->select('st.stop_id')->distinct()
                        ->from('gtfs_stop_times as st')
                        ->whereIn('st.trip_id', function ($trips) {
                            $trips->select('t.trip_id')
                                ->from('gtfs_trips as t')
                                ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
                                ->where('r.route_type', 2);
                        });
                })
                ->get(['stop_name', 'stop_lat', 'stop_lng'])
                ->map(fn ($stop) => [
                    'name' => (string) $stop->stop_name,
                    'lat' => (float) $stop->stop_lat,
                    'lng' => (float) $stop->stop_lng,
                ])
                ->all();
        });
    }
}
