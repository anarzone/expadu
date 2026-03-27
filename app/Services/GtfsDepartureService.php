<?php

namespace App\Services;

use App\Models\Gtfs\GtfsStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GtfsDepartureService
{
    /**
     * Get upcoming departures for a stop name from GTFS static data.
     * Falls back to mock data if GTFS tables are empty.
     *
     * @return array{stop_name: string, departures: array}
     */
    public function getDepartures(string $stopName = 'Ehrenfeld', int $limit = 10): array
    {
        // Check if GTFS data exists
        if (! $this->hasGtfsData()) {
            return $this->fallbackDepartures($stopName);
        }

        return Cache::remember("gtfs_departures_{$stopName}_{$limit}", 60, function () use ($stopName, $limit) {
            // Find stops matching the name
            $stops = GtfsStop::where('stop_name', 'ILIKE', "%{$stopName}%")
                ->where('location_type', 0) // actual stops, not stations
                ->pluck('stop_id');

            if ($stops->isEmpty()) {
                return $this->fallbackDepartures($stopName);
            }

            $now = Carbon::now();
            $currentTime = $now->format('H:i:s');
            $dayOfWeek = strtolower($now->format('l'));

            // Get upcoming stop_times for these stops
            $departures = DB::table('gtfs_stop_times')
                ->join('gtfs_trips', 'gtfs_stop_times.trip_id', '=', 'gtfs_trips.trip_id')
                ->join('gtfs_routes', 'gtfs_trips.route_id', '=', 'gtfs_routes.route_id')
                ->whereIn('gtfs_stop_times.stop_id', $stops)
                ->where('gtfs_stop_times.departure_time', '>=', $currentTime)
                ->orderBy('gtfs_stop_times.departure_time')
                ->limit($limit)
                ->select([
                    'gtfs_routes.route_short_name as line',
                    'gtfs_routes.route_long_name as route_name',
                    'gtfs_routes.route_color',
                    'gtfs_routes.route_type',
                    'gtfs_trips.trip_headsign as direction',
                    'gtfs_stop_times.departure_time',
                    'gtfs_stop_times.stop_sequence',
                ])
                ->get();

            if ($departures->isEmpty()) {
                return $this->fallbackDepartures($stopName);
            }

            // Group by line+direction, show next 3 times
            $grouped = [];
            foreach ($departures as $dep) {
                $key = $dep->line.'_'.$dep->direction;
                if (! isset($grouped[$key])) {
                    $grouped[$key] = [
                        'line' => $dep->line ?: '?',
                        'direction' => $dep->direction ?: $dep->route_name,
                        'color' => $dep->route_color ? "#{$dep->route_color}" : '#1A4CD4',
                        'type' => $this->routeTypeLabel($dep->route_type),
                        'departures' => [],
                    ];
                }
                if (count($grouped[$key]['departures']) < 3) {
                    $depTime = Carbon::createFromFormat('H:i:s', $dep->departure_time);
                    $minsAway = (int) $now->diffInMinutes($depTime, false);
                    if ($minsAway >= 0) {
                        $grouped[$key]['departures'][] = $minsAway;
                    }
                }
            }

            // Filter out lines with no upcoming departures
            $results = array_values(array_filter($grouped, fn ($g) => ! empty($g['departures'])));

            return [
                'stop_name' => $stopName,
                'source' => 'gtfs_static',
                'departures' => array_slice($results, 0, $limit),
            ];
        });
    }

    /**
     * Search stops by name.
     *
     * @return array<int, array{stop_id: string, stop_name: string, lat: float, lng: float}>
     */
    public function searchStops(string $query, int $limit = 10): array
    {
        if (! $this->hasGtfsData()) {
            return [];
        }

        return Cache::remember("gtfs_stops_search_{$query}_{$limit}", 300, function () use ($query, $limit) {
            return GtfsStop::where('stop_name', 'ILIKE', "%{$query}%")
                ->where('location_type', 0)
                ->limit($limit)
                ->get(['stop_id', 'stop_name', 'stop_lat', 'stop_lng'])
                ->map(fn ($s) => [
                    'stop_id' => $s->stop_id,
                    'name' => $s->stop_name,
                    'lat' => (float) $s->stop_lat,
                    'lng' => (float) $s->stop_lng,
                ])
                ->all();
        });
    }

    protected function hasGtfsData(): bool
    {
        return Cache::remember('gtfs_has_data', 3600, function () {
            return DB::table('gtfs_stops')->exists();
        });
    }

    protected function routeTypeLabel(int $type): string
    {
        return match ($type) {
            0 => 'tram',
            1 => 'subway',
            2 => 'rail',
            3 => 'bus',
            default => 'transit',
        };
    }

    /**
     * @return array{stop_name: string, source: string, departures: array}
     */
    protected function fallbackDepartures(string $stopName): array
    {
        return [
            'stop_name' => $stopName,
            'source' => 'mock',
            'departures' => [
                ['line' => '9', 'direction' => 'Königsforst', 'color' => '#1A4CD4', 'type' => 'tram', 'departures' => [3, 13, 23]],
                ['line' => '7', 'direction' => 'Frechen', 'color' => '#0A7C52', 'type' => 'tram', 'departures' => [7, 17, 27]],
                ['line' => '1', 'direction' => 'Bensberg', 'color' => '#E8914A', 'type' => 'tram', 'departures' => [11, 21, 31]],
                ['line' => 'S12', 'direction' => 'Düren', 'color' => '#C4271A', 'type' => 'rail', 'departures' => [5, 35, 65]],
                ['line' => 'RE1', 'direction' => 'Aachen Hbf', 'color' => '#7C3AED', 'type' => 'rail', 'departures' => [18, 48, 78]],
            ],
        ];
    }
}
