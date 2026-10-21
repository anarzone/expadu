<?php

namespace App\Services;

use App\Models\Gtfs\GtfsStop;
use App\Transit\DepartureTime;
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
            $trias = $this->withObservation($trias);
            // Store as fallback for when TRIAS fails (3 min TTL — keeps departure times accurate)
            Cache::put($fallbackKey, $trias + ['_cached_at' => now()->timestamp], 180);

            return $trias;
        }

        // TRIAS failed — use last known good result if fresh enough
        $lastGood = Cache::get($fallbackKey);
        if ($lastGood) {
            return $this->ageFallback($lastGood);
        }

        if (! $this->hasGtfsData()) {
            return $this->fallbackDepartures('Nearby');
        }

        $cacheKey = 'gtfs_departures_nearby_v2_'.round($lat, 3).'_'.round($lng, 3)."_{$limit}"; // gitleaks:allow (cache key name, not a secret)

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
    /**
     * Fetch live departures for several stops in one parallel round trip, so
     * the per-stop getDepartures() calls that follow read them from cache.
     *
     * @param  list<string>  $stopNames
     */
    public function prefetchDepartures(array $stopNames, int $limit = 10): void
    {
        $this->triasService->prefetchDepartures($stopNames, $limit);
    }

    public function getDepartures(string $stopName = 'Ehrenfeld', int $limit = 10): array
    {
        $fallbackKey = "trias_last_good_v2_{$stopName}";

        // Try TRIAS first for real-time data
        $trias = $this->triasService->getDepartures($stopName, $limit);
        if ($trias && ! empty($trias['departures'])) {
            $trias = $this->withObservation($trias);
            Cache::put($fallbackKey, $trias + ['_cached_at' => now()->timestamp], 300);

            return $trias;
        }

        // TRIAS failed — use last known good result if fresh enough
        $lastGood = Cache::get($fallbackKey);
        if ($lastGood) {
            return $this->ageFallback($lastGood);
        }

        return $this->getStaticDepartures($stopName, $limit);
    }

    /**
     * TRIAS returns relative minutes. Re-anchor a last-good snapshot before
     * serving it so an outage cannot make old countdowns look freshly fetched.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function ageFallback(array $result): array
    {
        $cachedAt = (int) ($result['countdown_at'] ?? $result['_cached_at'] ?? now()->timestamp);
        if (! array_key_exists('observed_at', $result)) {
            $result['observed_at'] = Carbon::createFromTimestamp($cachedAt)->toIso8601String();
        }
        $result['is_stale'] = true;
        $result['time_basis'] = 'stale';
        $result['countdown_at'] = now()->timestamp;
        $result['departures'] = array_map(function (array $departure): array {
            if (isset($departure['services'])) {
                $departure['services'] = array_map(fn (array $service): array => array_replace($service, ['time_basis' => 'stale']), $departure['services']);
            }

            return $departure;
        }, $result['departures'] ?? []);
        unset($result['_cached_at']);
        $elapsedMinutes = intdiv(max(0, now()->timestamp - $cachedAt), 60);

        if ($elapsedMinutes === 0) {
            return $result;
        }

        $result['departures'] = collect($result['departures'] ?? [])
            ->map(function (array $departure) use ($elapsedMinutes): array {
                $departure['departures'] = collect($departure['departures'] ?? [])
                    ->map(fn (int $minutes): int => $minutes - $elapsedMinutes)
                    ->filter(fn (int $minutes): bool => $minutes >= 0)
                    ->values()
                    ->all();

                return $departure;
            })
            ->filter(fn (array $departure): bool => $departure['departures'] !== [] || ($departure['cancelled'] ?? false))
            ->values()
            ->all();

        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function withObservation(array $result): array
    {
        $observed = array_key_exists('observed_at', $result) ? DepartureTime::parse($result['observed_at']) : now();
        $result['observed_at'] = $observed?->toIso8601String();
        $result['countdown_at'] ??= $observed?->timestamp ?? now()->timestamp;
        $result['is_stale'] = ($result['is_stale'] ?? false) || $observed === null;
        $services = collect($result['departures'] ?? [])->flatMap(fn ($departure) => $departure['services'] ?? []);
        $result['time_basis'] = $result['is_stale'] ? 'stale'
            : ($services->isNotEmpty() && $services->every(fn ($service) => ($service['time_basis'] ?? 'scheduled') === 'scheduled') ? 'scheduled' : 'realtime');

        return $result;
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

        return Cache::remember("gtfs_departures_v2_{$stopName}_{$limit}", $cacheTtl, function () use ($stopName, $limit, $hasRealtime) {
            // Find stops matching the name
            $stops = GtfsStop::where('stop_name', 'ILIKE', "%{$stopName}%")
                ->where('location_type', 0) // actual stops, not stations
                ->get(['stop_id', 'stop_name', 'stop_lat', 'stop_lng'])->keyBy('stop_id');

            if ($stops->isEmpty()) {
                return $this->fallbackDepartures($stopName);
            }

            $now = Carbon::now();
            $hasCalendar = DB::table('gtfs_calendar')->exists();
            $hasExceptions = DB::table('gtfs_calendar_dates')->exists();
            $departures = collect();

            // A GTFS service day can continue after midnight (for example 25:10).
            // Keep each row attached to its own calendar date, including tomorrow's
            // first services, rather than interpreting every row as today's trip.
            foreach ([-1, 0, 1] as $offset) {
                $serviceDay = $now->copy()->startOfDay()->addDays($offset);
                $date = $serviceDay->format('Y-m-d');
                $dayColumn = strtolower($serviceDay->format('l'));
                $seconds = max(0, ((int) $now->format('H') - $offset * 24) * 3600 + (int) $now->format('i') * 60 + (int) $now->format('s'));
                $lowerBound = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
                $query = DB::table('gtfs_stop_times')
                    ->join('gtfs_trips', 'gtfs_stop_times.trip_id', '=', 'gtfs_trips.trip_id')
                    ->join('gtfs_routes', 'gtfs_trips.route_id', '=', 'gtfs_routes.route_id')
                    ->whereIn('gtfs_stop_times.stop_id', $stops->keys())
                    ->where('gtfs_stop_times.departure_time', '>=', $lowerBound);

                if ($hasCalendar || $hasExceptions) {
                    $removed = DB::table('gtfs_calendar_dates')->where('date', $date)->where('exception_type', 2)->pluck('service_id');
                    $added = DB::table('gtfs_calendar_dates')->where('date', $date)->where('exception_type', 1)->pluck('service_id');
                    $query->where(function ($q) use ($dayColumn, $date, $removed, $added) {
                        $q->where(function ($normal) use ($dayColumn, $date, $removed) {
                            $normal->whereExists(function ($calendar) use ($dayColumn, $date) {
                                $calendar->select(DB::raw(1))->from('gtfs_calendar')
                                    ->whereColumn('gtfs_calendar.service_id', 'gtfs_trips.service_id')
                                    ->where("gtfs_calendar.{$dayColumn}", true)
                                    ->where('gtfs_calendar.start_date', '<=', $date)
                                    ->where('gtfs_calendar.end_date', '>=', $date);
                            })->whereNotIn('gtfs_trips.service_id', $removed);
                        });
                        if ($added->isNotEmpty()) {
                            $q->orWhereIn('gtfs_trips.service_id', $added);
                        }
                    });
                }

                $rows = $query->orderBy('gtfs_stop_times.departure_time')->limit($limit * 10)
                    ->selectRaw('gtfs_trips.trip_id, gtfs_routes.route_short_name as line,
                        gtfs_routes.route_long_name as route_name, gtfs_routes.route_color,
                        gtfs_routes.route_type, gtfs_trips.trip_headsign as direction,
                        gtfs_stop_times.departure_time, gtfs_stop_times.stop_id')->get();
                foreach ($rows as $row) {
                    $row->scheduled_at = $this->scheduledTime($serviceDay, $row->departure_time);
                    $row->service_date = $date;
                    if ($row->scheduled_at !== null && $row->scheduled_at->betweenIncluded($now, $now->copy()->addDay())) {
                        $departures->push($row);
                    }
                }
            }
            $departures = $departures->sortBy(fn ($row) => $row->scheduled_at->timestamp)
                ->unique(fn ($row) => $row->trip_id.'|'.$row->service_date);

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
                $rtDelaySeconds = null;
                $rtCancelled = false;
                $rtSkipped = false;
                $rtObserved = null;
                $updateDate = $realtimeUpdates[$dep->trip_id]['service_date'] ?? $now->format('Y-m-d');
                if ($hasRealtimeData && $dep->trip_id && $updateDate === $dep->service_date) {
                    $rtInfo = $this->realtimeService->getDelayForTripAtStop($dep->trip_id, $dep->stop_id);
                    if ($rtInfo) {
                        $rtDelaySeconds = (int) $rtInfo['delay'];
                        $rtDelay = (int) round($rtDelaySeconds / 60); // display only
                        $rtCancelled = $rtInfo['cancelled'];
                        $rtSkipped = $rtInfo['skipped'];
                        $rtObserved = DepartureTime::parse($rtInfo['observed_at'] ?? null);
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
                        'services' => [],
                        'delay' => 0,
                        'cancelled' => false,
                        'disrupted' => $isDisrupted,
                        'disruption_severity' => $isDisrupted ? $disruptedLines[$lineName] : null,
                    ];
                }

                if (count($grouped[$key]['services']) < 5) {
                    $depTime = $dep->scheduled_at;
                    if ($depTime === null) {
                        continue;
                    }
                    $actual = $rtDelay !== null ? $depTime->copy()->addSeconds($rtDelaySeconds) : $depTime;
                    if ($actual->lessThan($now)) {
                        continue;
                    }
                    $actualMins = max(0, (int) $now->diffInMinutes($actual, false));
                    $stale = $rtDelay !== null && ($rtObserved === null || $now->timestamp - $rtObserved->timestamp > 90);
                    $grouped[$key]['services'][] = [
                        'id' => 'gtfs:'.hash('sha256', $dep->trip_id.'|'.$dep->stop_id.'|'.$depTime->toIso8601String()),
                        'departure_at' => $actual->toIso8601String(),
                        'scheduled_at' => $depTime->toIso8601String(),
                        'expected_at' => $rtDelay !== null ? $actual->toIso8601String() : null,
                        'observed_at' => $rtDelay !== null ? $rtObserved?->toIso8601String() : $now->toIso8601String(),
                        'time_basis' => $stale ? 'stale' : ($rtDelay !== null ? 'realtime' : 'scheduled'),
                        'cancelled' => $rtCancelled,
                        'delay' => $rtDelay ?? 0,
                        'boarding_point' => [
                            'id' => $dep->stop_id,
                            'name' => $stops->get($dep->stop_id)?->stop_name,
                            'platform' => null,
                        ],
                    ];
                    if (! $rtCancelled && ! in_array($actualMins, $grouped[$key]['departures'], true)) {
                        $grouped[$key]['departures'][] = $actualMins;
                    }
                    if (count($grouped[$key]['services']) === 1) {
                        $grouped[$key]['delay'] = max(0, $rtDelay ?? 0);
                        $grouped[$key]['cancelled'] = $rtCancelled;
                    }
                }
            }

            // Filter out lines with no upcoming departures
            $results = array_values(array_filter($grouped, fn ($g) => ! empty($g['services'])));
            $services = collect($results)->flatMap(fn ($group) => $group['services']);
            $rtServices = $services->filter(fn ($service) => $service['expected_at'] !== null);
            $stale = $rtServices->contains(fn ($service) => $service['time_basis'] === 'stale');
            $observed = $rtServices->isEmpty() ? $now->toIso8601String()
                : ($rtServices->contains(fn ($service) => $service['observed_at'] === null) ? null : $rtServices->sortBy(fn ($service) => DepartureTime::parse($service['observed_at'])->timestamp)->first()['observed_at']);

            return [
                'stop_name' => $stopName,
                'source' => $rtServices->isNotEmpty() ? 'gtfs_rt' : 'gtfs_static',
                'observed_at' => $observed,
                'countdown_at' => $now->timestamp,
                'is_stale' => $stale,
                'time_basis' => $stale ? 'stale' : ($rtServices->count() === $services->count() ? 'realtime' : 'scheduled'),
                'departures' => array_slice($results, 0, $limit),
            ];
        });
    }

    private function scheduledTime(Carbon $day, string $time): ?Carbon
    {
        if (! preg_match('/^(\d{1,3}):([0-5]\d):([0-5]\d)$/D', $time, $parts)) {
            return null;
        }
        $hours = (int) $parts[1];

        return $day->copy()->startOfDay()->addDays(intdiv($hours, 24))->setTime($hours % 24, (int) $parts[2], (int) $parts[3]);
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
                // VRS names carry the city prefix ("Köln Heumarkt") — noise on
                // a board that is already inside Cologne.
                ->map(fn ($name) => preg_replace('/^Köln[ -]/u', '', (string) $name))
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
     *
     * $locationSource records HOW the board's origin was resolved (gps /
     * confirmed / last_ping / home / default) — the key signal when a user
     * reports the board rooted at the wrong station.
     */
    public static function logDepartureDebug(int $userId, string $page, array $result, ?float $lat = null, ?float $lng = null, ?string $locationSource = null): void
    {
        try {
            $key = "departure_debug:{$userId}";
            $timestamp = now()->timestamp;

            $lines = collect($result['departures'] ?? [])->pluck('line')->all();

            $entry = json_encode([
                'page' => $page,
                'stop' => $result['stop_name'] ?? null,
                'source' => $result['source'] ?? null,
                'location_source' => $locationSource,
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
