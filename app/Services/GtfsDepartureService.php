<?php

namespace App\Services;

use App\Models\Gtfs\GtfsStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GtfsDepartureService
{
    /**
     * Get departures for the nearest stop to given coordinates.
     * Uses PostGIS-free distance calculation (Haversine approximation).
     *
     * @return array{stop_name: string, source: string, departures: array}
     */
    public function getDeparturesNearby(float $lat, float $lng, int $limit = 10): array
    {
        if (! $this->hasGtfsData()) {
            return $this->fallbackDepartures('Nearby');
        }

        $cacheKey = "gtfs_departures_nearby_{$lat}_{$lng}_{$limit}";

        return Cache::remember($cacheKey, 60, function () use ($lat, $lng, $limit) {
            // Find nearest stop using simple distance formula (good enough for city scale)
            $nearestStop = GtfsStop::where('location_type', 0)
                ->whereNotNull('stop_lat')
                ->whereNotNull('stop_lng')
                ->selectRaw('*, (ABS(stop_lat - ?) + ABS(stop_lng - ?)) as dist', [$lat, $lng])
                ->orderBy('dist')
                ->first();

            if (! $nearestStop) {
                return $this->fallbackDepartures('Nearby');
            }

            return $this->getDepartures($nearestStop->stop_name, $limit);
        });
    }

    /**
     * Get upcoming departures for a stop name from GTFS static data.
     * Falls back to mock data if GTFS tables are empty.
     *
     * @return array{stop_name: string, source: string, departures: array}
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
            $today = $now->format('Y-m-d');
            $dayColumn = strtolower($now->format('l')); // monday, tuesday, etc.

            // Build query — join calendar if available to filter by today's service
            $hasCalendar = DB::table('gtfs_calendar')->exists();

            $query = DB::table('gtfs_stop_times')
                ->join('gtfs_trips', 'gtfs_stop_times.trip_id', '=', 'gtfs_trips.trip_id')
                ->join('gtfs_routes', 'gtfs_trips.route_id', '=', 'gtfs_routes.route_id')
                ->whereIn('gtfs_stop_times.stop_id', $stops)
                ->where('gtfs_stop_times.departure_time', '>', $currentTime);

            // Filter by today's active services using calendar + calendar_dates
            if ($hasCalendar) {
                // Services removed today via calendar_dates (exception_type=2)
                $removedToday = DB::table('gtfs_calendar_dates')
                    ->where('date', $today)
                    ->where('exception_type', 2)
                    ->pluck('service_id');

                // Services added today via calendar_dates (exception_type=1)
                $addedToday = DB::table('gtfs_calendar_dates')
                    ->where('date', $today)
                    ->where('exception_type', 1)
                    ->pluck('service_id');

                $query->where(function ($q) use ($dayColumn, $today, $removedToday, $addedToday) {
                    // Normal calendar services running today, minus removed exceptions
                    $q->where(function ($q2) use ($dayColumn, $today, $removedToday) {
                        $q2->whereExists(function ($sub) use ($dayColumn, $today) {
                            $sub->select(DB::raw(1))
                                ->from('gtfs_calendar')
                                ->whereColumn('gtfs_calendar.service_id', 'gtfs_trips.service_id')
                                ->where("gtfs_calendar.{$dayColumn}", true)
                                ->where('gtfs_calendar.start_date', '<=', $today)
                                ->where('gtfs_calendar.end_date', '>=', $today);
                        });
                        if ($removedToday->isNotEmpty()) {
                            $q2->whereNotIn('gtfs_trips.service_id', $removedToday);
                        }
                    });

                    // OR added today via exceptions
                    if ($addedToday->isNotEmpty()) {
                        $q->orWhereIn('gtfs_trips.service_id', $addedToday);
                    }
                });
            }

            $departures = $query
                ->groupBy(
                    'gtfs_trips.trip_id',
                    'gtfs_routes.route_short_name',
                    'gtfs_routes.route_long_name',
                    'gtfs_routes.route_color',
                    'gtfs_routes.route_type',
                    'gtfs_trips.trip_headsign',
                )
                ->orderByRaw('MIN(gtfs_stop_times.departure_time)')
                ->limit($limit * 10)
                ->selectRaw('
                    gtfs_routes.route_short_name as line,
                    gtfs_routes.route_long_name as route_name,
                    gtfs_routes.route_color,
                    gtfs_routes.route_type,
                    gtfs_trips.trip_headsign as direction,
                    MIN(gtfs_stop_times.departure_time) as departure_time
                ')
                ->get();

            if ($departures->isEmpty()) {
                return $this->fallbackDepartures($stopName);
            }

            // Get disrupted lines to mark them
            $disruptedLines = app(DisruptionService::class)->getDisruptedLines();

            // Group by line+direction, collect distinct departure minutes
            $grouped = [];
            foreach ($departures as $dep) {
                // Fix missing line name — fall back to route_long_name or extract from it
                $lineName = $dep->line ?: '';
                if (! $lineName && $dep->route_name) {
                    // Extract line number from route name like "RE 8" or "S12 Köln-Düren"
                    if (preg_match('/^((?:RE|RB|S)\s*\d+|\d+)/', $dep->route_name, $m)) {
                        $lineName = trim($m[1]);
                    } else {
                        $lineName = mb_substr($dep->route_name, 0, 6);
                    }
                }
                if (! $lineName) {
                    continue; // skip completely unnamed routes
                }

                $key = $lineName.'_'.$dep->direction;
                if (! isset($grouped[$key])) {
                    $isDisrupted = isset($disruptedLines[$lineName]);
                    $grouped[$key] = [
                        'line' => $lineName,
                        'direction' => $dep->direction ?: $dep->route_name ?: 'Unknown',
                        'color' => $dep->route_color ? "#{$dep->route_color}" : '#1A4CD4',
                        'type' => $this->routeTypeLabel($dep->route_type),
                        'departures' => [],
                        'disrupted' => $isDisrupted,
                        'disruption_severity' => $isDisrupted ? $disruptedLines[$lineName] : null,
                    ];
                }
                if (count($grouped[$key]['departures']) < 5) {
                    $depTime = Carbon::createFromFormat('H:i:s', $dep->departure_time);
                    $minsAway = (int) $now->diffInMinutes($depTime, false);
                    if ($minsAway > 0 && ! in_array($minsAway, $grouped[$key]['departures'], true)) {
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

    /**
     * Get ordered stop coordinates for a transit line between two stops.
     * Used for drawing transit route segments on the map.
     *
     * @return array{line: string, color: string, stops: array<int, array{name: string, lat: float, lng: float}>, from_stop: string, to_stop: string}|null
     */
    public function getRouteStopSequence(string $line, string $fromStopName, string $toStopName): ?array
    {
        return Cache::remember("route_stops_{$line}_{$fromStopName}_{$toStopName}", 3600, function () use ($line, $fromStopName, $toStopName) {
            // Find route for this line
            $route = DB::table('gtfs_routes')
                ->where('route_short_name', $line)
                ->first();

            if (! $route) {
                return null;
            }

            // Find a trip that visits both stops
            $trip = DB::table('gtfs_trips as t')
                ->where('t.route_id', $route->route_id)
                ->whereExists(function ($q) use ($fromStopName) {
                    $q->select(DB::raw(1))
                        ->from('gtfs_stop_times as st1')
                        ->join('gtfs_stops as s1', 's1.stop_id', '=', 'st1.stop_id')
                        ->whereColumn('st1.trip_id', 't.trip_id')
                        ->where('s1.stop_name', 'ILIKE', "%{$fromStopName}%");
                })
                ->whereExists(function ($q) use ($toStopName) {
                    $q->select(DB::raw(1))
                        ->from('gtfs_stop_times as st2')
                        ->join('gtfs_stops as s2', 's2.stop_id', '=', 'st2.stop_id')
                        ->whereColumn('st2.trip_id', 't.trip_id')
                        ->where('s2.stop_name', 'ILIKE', "%{$toStopName}%");
                })
                ->first();

            if (! $trip) {
                return null;
            }

            // Get all stops for this trip in sequence order
            $allStops = DB::table('gtfs_stop_times as st')
                ->join('gtfs_stops as s', 's.stop_id', '=', 'st.stop_id')
                ->where('st.trip_id', $trip->trip_id)
                ->orderBy('st.stop_sequence')
                ->get(['s.stop_name', 's.stop_lat', 's.stop_lng', 'st.stop_sequence']);

            // Find the indices of from and to stops
            $fromIdx = null;
            $toIdx = null;
            foreach ($allStops as $i => $stop) {
                if ($fromIdx === null && str_contains(mb_strtolower($stop->stop_name), mb_strtolower($fromStopName))) {
                    $fromIdx = $i;
                }
                if (str_contains(mb_strtolower($stop->stop_name), mb_strtolower($toStopName))) {
                    $toIdx = $i;
                }
            }

            if ($fromIdx === null || $toIdx === null || $fromIdx >= $toIdx) {
                // Try reverse direction
                if ($fromIdx !== null && $toIdx !== null && $fromIdx > $toIdx) {
                    [$fromIdx, $toIdx] = [$toIdx, $fromIdx];
                } else {
                    return null;
                }
            }

            $slice = $allStops->slice($fromIdx, $toIdx - $fromIdx + 1)->values();

            return [
                'line' => $line,
                'color' => $route->route_color ? "#{$route->route_color}" : '#1A4CD4',
                'from_stop' => $slice->first()->stop_name,
                'to_stop' => $slice->last()->stop_name,
                'stops' => $slice->map(fn ($s) => [
                    'name' => $s->stop_name,
                    'lat' => (float) $s->stop_lat,
                    'lng' => (float) $s->stop_lng,
                ])->all(),
            ];
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
            'source' => 'unavailable',
            'departures' => [],
        ];
    }
}
