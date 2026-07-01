<?php

namespace App\Services;

use App\Models\Gtfs\GtfsStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class GtfsDepartureService
{
    public function __construct(
        private readonly VrsRealtimeService $realtimeService,
        private readonly VrsTriasService $triasService,
    ) {}

    /**
     * Get departures for the nearest stop to given coordinates.
     * Tries TRIAS first (real-time), falls back to GTFS static.
     *
     * @return array{stop_name: string, source: string, departures: array}
     */
    public function getDeparturesNearby(float $lat, float $lng, int $limit = 10): array
    {
        $fallbackKey = 'trias_last_good_nearby_'.round($lat, 3).'_'.round($lng, 3);

        // Try TRIAS first for real-time data
        $trias = $this->triasService->getDeparturesNearby($lat, $lng, $limit);
        if ($trias && ! empty($trias['departures'])) {
            // Store as fallback for when TRIAS fails (3 min TTL — keeps departure times accurate)
            Cache::put($fallbackKey, $trias, 180);

            return $trias;
        }

        // TRIAS failed — use last known good result if fresh enough
        $lastGood = Cache::get($fallbackKey);
        if ($lastGood) {
            return $lastGood;
        }

        if (! $this->hasGtfsData()) {
            return $this->fallbackDepartures('Nearby');
        }

        $cacheKey = 'gtfs_departures_nearby_'.round($lat, 3).'_'.round($lng, 3)."_{$limit}";

        return Cache::remember($cacheKey, 60, function () use ($lat, $lng, $limit) {
            // Try up to 5 nearest stops until we find one with departures
            $nearestStops = GtfsStop::where('location_type', 0)
                ->whereNotNull('stop_lat')
                ->whereNotNull('stop_lng')
                ->selectRaw('*, (ABS(stop_lat - ?) + ABS(stop_lng - ?)) as dist', [$lat, $lng])
                ->orderBy('dist')
                ->limit(5)
                ->get();

            if ($nearestStops->isEmpty()) {
                return $this->fallbackDepartures('Nearby');
            }

            foreach ($nearestStops as $stop) {
                $result = $this->getStaticDepartures($stop->stop_name, $limit);
                if (! empty($result['departures'])) {
                    return $result;
                }
            }

            return $this->fallbackDepartures($nearestStops->first()->stop_name);
        });
    }

    /**
     * Get upcoming departures for a stop name.
     * Tries TRIAS first (real-time), falls back to GTFS static + GTFS-RT.
     *
     * @return array{stop_name: string, source: string, departures: array}
     */
    public function getDepartures(string $stopName = 'Ehrenfeld', int $limit = 10): array
    {
        $fallbackKey = "trias_last_good_{$stopName}";

        // Try TRIAS first for real-time data
        $trias = $this->triasService->getDepartures($stopName, $limit);
        if ($trias && ! empty($trias['departures'])) {
            Cache::put($fallbackKey, $trias, 300);

            return $trias;
        }

        // TRIAS failed — use last known good result if fresh enough
        $lastGood = Cache::get($fallbackKey);
        if ($lastGood) {
            return $lastGood;
        }

        return $this->getStaticDepartures($stopName, $limit);
    }

    /**
     * Get departures from GTFS static data with optional GTFS-RT overlay.
     *
     * @return array{stop_name: string, source: string, departures: array}
     */
    public function getStaticDepartures(string $stopName = 'Ehrenfeld', int $limit = 10): array
    {
        // Check if GTFS data exists
        if (! $this->hasGtfsData()) {
            return $this->fallbackDepartures($stopName);
        }

        $hasRealtime = config('services.vrs.enabled');
        $cacheTtl = $hasRealtime ? 30 : 60;

        return Cache::remember("gtfs_departures_{$stopName}_{$limit}", $cacheTtl, function () use ($stopName, $limit, $hasRealtime) {
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
                    gtfs_trips.trip_id,
                    gtfs_routes.route_short_name as line,
                    gtfs_routes.route_long_name as route_name,
                    gtfs_routes.route_color,
                    gtfs_routes.route_type,
                    gtfs_trips.trip_headsign as direction,
                    MIN(gtfs_stop_times.departure_time) as departure_time,
                    MIN(gtfs_stop_times.stop_id) as stop_id
                ')
                ->get();

            if ($departures->isEmpty()) {
                return $this->fallbackDepartures($stopName);
            }

            // Get disrupted lines to mark them
            $disruptedLines = app(DisruptionService::class)->getDisruptedLines();

            // Fetch real-time updates if enabled
            $realtimeUpdates = $hasRealtime ? $this->realtimeService->getTripUpdates() : [];
            $hasRealtimeData = ! empty($realtimeUpdates);

            // Group by line+direction, collect distinct departure minutes with real-time overlay
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

                // Look up real-time data for this trip
                $rtDelay = null;
                $rtCancelled = false;
                $rtSkipped = false;
                if ($hasRealtimeData && $dep->trip_id) {
                    $rtInfo = $this->realtimeService->getDelayForTripAtStop($dep->trip_id, $dep->stop_id);
                    if ($rtInfo) {
                        $rtDelay = (int) round($rtInfo['delay'] / 60); // seconds → minutes
                        $rtCancelled = $rtInfo['cancelled'];
                        $rtSkipped = $rtInfo['skipped'];
                    }
                }

                // Skip this departure if the stop is skipped in real-time
                if ($rtSkipped) {
                    continue;
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
                        'delay' => 0,
                        'cancelled' => false,
                        'disrupted' => $isDisrupted,
                        'disruption_severity' => $isDisrupted ? $disruptedLines[$lineName] : null,
                    ];
                }

                if (count($grouped[$key]['departures']) < 5) {
                    $depTime = Carbon::createFromFormat('H:i:s', $dep->departure_time);
                    $scheduledMins = (int) $now->diffInMinutes($depTime, false);

                    // Apply real-time delay to departure time
                    $actualMins = $rtDelay !== null ? $scheduledMins + $rtDelay : $scheduledMins;

                    if ($actualMins > 0 && ! in_array($actualMins, $grouped[$key]['departures'], true)) {
                        $grouped[$key]['departures'][] = $actualMins;
                    }

                    // Use the first (nearest) departure's RT info as the group indicator
                    if (count($grouped[$key]['departures']) === 1 && $rtDelay !== null) {
                        $grouped[$key]['delay'] = max(0, $rtDelay);
                        $grouped[$key]['cancelled'] = $rtCancelled;
                    }
                }
            }

            // Filter out lines with no upcoming departures
            $results = array_values(array_filter($grouped, fn ($g) => ! empty($g['departures'])));

            return [
                'stop_name' => $stopName,
                'source' => $hasRealtimeData ? 'gtfs_rt' : 'gtfs_static',
                'departures' => array_slice($results, 0, $limit),
            ];
        });
    }

    /**
     * Static route context for a line + headsign at a stop: the GTFS travel
     * direction (direction_id) and the next stops ridden after this one ("via").
     * Timetable-static, so cached long; a miss (renamed headsigns, no GTFS)
     * degrades to nulls and the board simply renders ungrouped.
     *
     * @return array{direction: int|null, via: array<int, string>}
     */
    public function routeContext(string $stopName, string $line, string $destination): array
    {
        if ($line === '' || ! $this->hasGtfsData()) {
            return ['direction' => null, 'via' => []];
        }

        $cacheKey = 'gtfs_route_ctx_'.md5("{$stopName}|{$line}|{$destination}");

        return Cache::remember($cacheKey, 21600, function () use ($stopName, $line, $destination) {
            $stopIds = GtfsStop::where('stop_name', 'ILIKE', "%{$stopName}%")
                ->where('location_type', 0)
                ->pluck('stop_id');

            if ($stopIds->isEmpty()) {
                return ['direction' => null, 'via' => []];
            }

            // A representative trip of this line through this stop whose
            // headsign matches the live destination (exact, else fuzzy — TRIAS
            // destinations don't always equal GTFS headsigns verbatim).
            $trip = DB::table('gtfs_stop_times as st')
                ->join('gtfs_trips as t', 't.trip_id', '=', 'st.trip_id')
                ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
                ->whereIn('st.stop_id', $stopIds)
                ->where('r.route_short_name', $line)
                ->where(fn ($q) => $q
                    ->where('t.trip_headsign', $destination)
                    ->orWhere('t.trip_headsign', 'ILIKE', "%{$destination}%")
                    ->orWhereRaw('? ILIKE \'%\' || t.trip_headsign || \'%\'', [$destination]))
                ->orderByRaw('(t.trip_headsign = ?) DESC', [$destination])
                ->select('t.trip_id', 't.direction_id', 'st.stop_sequence')
                ->first();

            if ($trip === null) {
                return ['direction' => null, 'via' => []];
            }

            $via = DB::table('gtfs_stop_times as st')
                ->join('gtfs_stops as s', 's.stop_id', '=', 'st.stop_id')
                ->where('st.trip_id', $trip->trip_id)
                ->where('st.stop_sequence', '>', $trip->stop_sequence)
                ->orderBy('st.stop_sequence')
                ->limit(2)
                ->pluck('s.stop_name')
                ->all();

            return [
                'direction' => $trip->direction_id !== null ? (int) $trip->direction_id : null,
                'via' => $via,
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

    /**
     * Log departure response to Redis for debugging (30-day TTL).
     * Key: departure_debug:{user_id}, sorted set scored by timestamp.
     */
    public static function logDepartureDebug(int $userId, string $page, array $result, ?float $lat = null, ?float $lng = null): void
    {
        try {
            $key = "departure_debug:{$userId}";
            $timestamp = now()->timestamp;

            $lines = collect($result['departures'] ?? [])->pluck('line')->all();

            $entry = json_encode([
                'page' => $page,
                'stop' => $result['stop_name'] ?? null,
                'source' => $result['source'] ?? null,
                'lines' => $lines,
                'count' => count($result['departures'] ?? []),
                'lat' => $lat ? round($lat, 6) : null,
                'lng' => $lng ? round($lng, 6) : null,
                'at' => now()->toIso8601String(),
            ]);

            Redis::zadd($key, $timestamp, $entry);
            Redis::zremrangebyscore($key, 0, now()->subMonth()->timestamp);
            Redis::expire($key, 2592000);
        } catch (\Throwable) {
            // Redis unavailable — skip debug logging silently
        }
    }
}
