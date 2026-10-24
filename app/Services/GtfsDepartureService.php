<?php

namespace App\Services;

use App\Models\Gtfs\GtfsStop;
use App\Transit\DepartureTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class GtfsDepartureService
{
    /** Changes with each timetable import (gtfs:refresh), so lookups keyed by it start over. */
    public const GENERATION_KEY = 'gtfs_generation';

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
            $stops = $this->stopsNamed($stopName);

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
                    $this->runningOn($query, $date, $dayColumn);
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

            $departures = $this->toRealEnds($departures, $stops->keys()->map(fn ($id) => (string) $id)->all());

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
     * direction (direction_id), the next stops ridden after this one ("via")
     * and, when today's runs of that headsign end elsewhere, where (`end`:
     * live times keep 16's "Bonn-Bad Godesberg" while it ends at Ubierring).
     * Timetable-static, so cached long; a miss (renamed headsigns, no GTFS)
     * degrades to nulls and the board simply renders ungrouped.
     *
     * @return array{direction: int|null, via: array<int, string>, end?: string|null}
     */
    public function routeContext(string $stopName, string $line, string $destination): array
    {
        if ($line === '' || ! $this->hasGtfsData()) {
            return ['direction' => null, 'via' => []];
        }

        // Per service day: works change a line's run (16 ends at Ubierring).
        $cacheKey = 'gtfs_route_ctx_v2_'.self::serviceDay()->format('Ymd').'_'.md5("{$stopName}|{$line}|{$destination}");

        return Cache::remember($cacheKey, 21600, function () use ($stopName, $line, $destination) {
            $stopIds = $this->stopsNamed($stopName)->pluck('stop_id');

            if ($stopIds->isEmpty()) {
                return ['direction' => null, 'via' => []];
            }

            // A representative trip of this line through this stop today whose
            // headsign matches the live destination (exact, else fuzzy — TRIAS
            // destinations don't always equal GTFS headsigns verbatim).
            $query = DB::table('gtfs_stop_times as st')
                ->join('gtfs_trips as t', 't.trip_id', '=', 'st.trip_id')
                ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
                ->whereIn('st.stop_id', $stopIds)
                ->where('r.route_short_name', $line)
                ->where(fn ($q) => $q
                    ->where('t.trip_headsign', $destination)
                    ->orWhere('t.trip_headsign', 'ILIKE', "%{$destination}%")
                    ->orWhereRaw('? ILIKE \'%\' || t.trip_headsign || \'%\'', [$destination]));
            $this->runningToday($query, 't');
            // Where today's runs of it most often end.
            $lastStop = DB::table('gtfs_stop_times as st')
                ->join('gtfs_stops as s', 's.stop_id', '=', 'st.stop_id')
                ->whereIn('st.trip_id', (clone $query)->select('t.trip_id'))
                ->whereRaw('st.stop_sequence = (select max(x.stop_sequence) from gtfs_stop_times x where x.trip_id = st.trip_id)')
                ->groupBy('s.stop_name')
                ->orderByRaw('count(*) desc')
                ->value('s.stop_name');
            $trip = $query
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
                'end' => self::realEnd($destination, $lastStop),
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
     * The stops whose name contains this one (actual stops, not stations),
     * keyed by stop_id. Read once an hour per name: a board asks per line.
     *
     * @return Collection<string, object{stop_id: string, stop_name: string, stop_lat: float|null, stop_lng: float|null}>
     */
    /**
     * Each line's usual gap at a stop around now, by direction ("12|Merkenich"
     * => 20): the median minutes between its timetabled runs from two hours
     * before to two hours after, so a board can tell an ordinary 20-minute
     * service from the night's last trams. Lines with fewer than three runs
     * in that window have none.
     *
     * @return array<string, int>
     */
    public function headways(string $stopName, ?Carbon $at = null): array
    {
        if (! $this->hasGtfsData()) {
            return [];
        }
        $at = ($at ?? Carbon::now())->copy()->setTimezone('Europe/Berlin');

        return Cache::remember('gtfs_headways:v1:'.Cache::get(self::GENERATION_KEY, 0).':'.md5(mb_strtolower($stopName)).':'.$at->format('Y-m-d-H'), 3600, function () use ($stopName, $at) {
            $stops = $this->stopsNamed($stopName);
            if ($stops->isEmpty()) {
                return [];
            }
            $from = $at->copy()->subHours(2);
            $to = $at->copy()->addHours(2);
            $hasCalendar = DB::table('gtfs_calendar')->exists() || DB::table('gtfs_calendar_dates')->exists();
            $runs = [];
            // Yesterday's service day runs past midnight (25:10), as getStaticDepartures reads it.
            foreach ([-1, 0] as $offset) {
                $serviceDay = $at->copy()->startOfDay()->addDays($offset);
                $clock = fn (Carbon $t) => sprintf('%02d:%02d:00', intdiv((int) $serviceDay->diffInMinutes($t, false), 60), (int) $serviceDay->diffInMinutes($t, false) % 60);
                if ($to->lt($serviceDay)) {
                    continue;
                }
                $query = DB::table('gtfs_stop_times')
                    ->join('gtfs_trips', 'gtfs_stop_times.trip_id', '=', 'gtfs_trips.trip_id')
                    ->join('gtfs_routes', 'gtfs_trips.route_id', '=', 'gtfs_routes.route_id')
                    ->whereIn('gtfs_stop_times.stop_id', $stops->keys())
                    ->whereBetween('gtfs_stop_times.departure_time', [$from->lt($serviceDay) ? '00:00:00' : $clock($from), $clock($to)]);
                if ($hasCalendar) {
                    $this->runningOn($query, $serviceDay->format('Y-m-d'), strtolower($serviceDay->format('l')));
                }
                foreach ($query->get(['gtfs_routes.route_short_name as line', 'gtfs_trips.trip_headsign as dest', 'gtfs_stop_times.departure_time']) as $row) {
                    $time = $this->scheduledTime($serviceDay, $row->departure_time);
                    if ($time !== null && $row->line) {
                        $runs[$row->line.'|'.$row->dest][] = $time->getTimestamp();
                    }
                }
            }

            $headways = [];
            foreach ($runs as $key => $times) {
                $times = array_values(array_unique($times));
                sort($times);
                if (count($times) < 3) {
                    continue;
                }
                $gaps = [];
                for ($i = 1; $i < count($times); $i++) {
                    $gaps[] = intdiv($times[$i] - $times[$i - 1], 60);
                }
                sort($gaps);
                $headways[$key] = $gaps[intdiv(count($gaps), 2)];
            }

            return $headways;
        });
    }

    /**
     * Each run towards where it really ends. Works keep the usual headsign:
     * line 16 said "Bonn-Bad Godesberg" while it ended at Ubierring (17 Oct
     * to 2 Nov 2026). A headsign the run's last stop doesn't carry gives way
     * to that stop's name, less "Köln". A run that ends here doesn't leave
     * from here (line 9 ended at Neumarkt).
     *
     * @param  Collection<int, object>  $departures
     * @param  list<string>  $here  the station's stop ids
     * @return Collection<int, object>
     */
    private function toRealEnds(Collection $departures, array $here): Collection
    {
        $last = DB::table('gtfs_stop_times as st')
            ->join('gtfs_stops as s', 's.stop_id', '=', 'st.stop_id')
            ->whereIn('st.trip_id', $departures->pluck('trip_id')->unique()->values())
            ->whereRaw('st.stop_sequence = (select max(x.stop_sequence) from gtfs_stop_times x where x.trip_id = st.trip_id)')
            ->get(['st.trip_id', 'st.stop_id', 's.stop_name'])
            ->keyBy('trip_id');
        $departures = $departures->reject(fn (object $dep) => in_array((string) ($last[$dep->trip_id]->stop_id ?? ''), $here, true))->values();
        $ends = $last->map(fn (object $row) => $row->stop_name);

        return $departures->map(function (object $dep) use ($ends) {
            $end = self::realEnd($dep->direction, $ends[$dep->trip_id] ?? null);
            if ($end !== null) {
                $dep->direction = $end;
            }

            return $dep;
        });
    }

    /**
     * The last stop's name, less "Köln", when the headsign isn't it; null
     * when it is. Either may carry the district: "Braunsfeld Eupener Str."
     * ends at "Köln Eupener Str.".
     */
    private static function realEnd(?string $headsign, ?string $lastStop): ?string
    {
        if ($lastStop === null || ! $headsign) {
            return null;
        }
        $plain = fn (string $name) => mb_strtolower(trim((string) preg_replace(['/^Köln[ -]/u', '/[\s-]+/u'], ['', ' '], $name)));
        $end = (string) preg_replace('/^Köln[ -]/u', '', $lastStop);

        return str_contains($plain($end), $plain($headsign)) || str_contains($plain($headsign), $plain($end)) ? null : $end;
    }

    /**
     * Today's service day: until 03:00 the night still belongs to yesterday's
     * (KVB's works also start and end at about 03:00).
     */
    public static function serviceDay(): Carbon
    {
        return Carbon::now('Europe/Berlin')->subHours(3)->startOfDay();
    }

    /**
     * Only the trips running on today's service day, so a line's route and
     * stops follow the timetable for works (lines 16 and 17 split, 17 Oct to
     * 2 Nov 2026) rather than the year's usual run. `$trips` is the query's
     * name for gtfs_trips. A feed without a calendar isn't filtered.
     */
    public function runningToday(Builder $query, string $trips = 'gtfs_trips'): void
    {
        $hasCalendar = Cache::remember('gtfs_has_calendar:'.Cache::get(self::GENERATION_KEY, 0), 3600, fn () => DB::table('gtfs_calendar')->exists() || DB::table('gtfs_calendar_dates')->exists());
        if ($hasCalendar) {
            $day = self::serviceDay();
            $this->runningOn($query, $day->format('Y-m-d'), strtolower($day->format('l')), $trips);
        }
    }

    /**
     * Only trips whose service runs on the date: its weekday in the calendar,
     * less the dates taken out, plus the dates added.
     */
    private function runningOn(Builder $query, string $date, string $dayColumn, string $trips = 'gtfs_trips'): void
    {
        $removed = DB::table('gtfs_calendar_dates')->where('date', $date)->where('exception_type', 2)->pluck('service_id');
        $added = DB::table('gtfs_calendar_dates')->where('date', $date)->where('exception_type', 1)->pluck('service_id');
        $query->where(function ($q) use ($dayColumn, $date, $removed, $added, $trips) {
            $q->where(function ($normal) use ($dayColumn, $date, $removed, $trips) {
                $normal->whereExists(function ($calendar) use ($dayColumn, $date, $trips) {
                    $calendar->select(DB::raw(1))->from('gtfs_calendar')
                        ->whereColumn('gtfs_calendar.service_id', "{$trips}.service_id")
                        ->where("gtfs_calendar.{$dayColumn}", true)
                        ->where('gtfs_calendar.start_date', '<=', $date)
                        ->where('gtfs_calendar.end_date', '>=', $date);
                })->whereNotIn("{$trips}.service_id", $removed);
            });
            if ($added->isNotEmpty()) {
                $q->orWhereIn("{$trips}.service_id", $added);
            }
        });
    }

    private function stopsNamed(string $stopName): Collection
    {
        $key = 'gtfs_stops_named:v1:'.Cache::get(self::GENERATION_KEY, 0).':'.md5(mb_strtolower($stopName));
        $rows = Cache::remember($key, 3600, fn () => GtfsStop::where('stop_name', 'ILIKE', "%{$stopName}%")
            ->where('location_type', 0)
            ->get(['stop_id', 'stop_name', 'stop_lat', 'stop_lng'])
            ->map(fn (GtfsStop $stop) => $stop->only(['stop_id', 'stop_name', 'stop_lat', 'stop_lng']))
            ->all());

        return collect($rows)->map(fn (array $stop) => (object) $stop)->keyBy('stop_id');
    }

    /**
     * Whether a timetable is loaded: remembered an hour once it is, but only a
     * minute while it isn't, so an empty moment (before the first import)
     * doesn't leave every board "unavailable" for the hour after.
     */
    protected function hasGtfsData(): bool
    {
        if (Cache::get('gtfs_has_data') === true) {
            return true;
        }

        $has = DB::table('gtfs_stops')->exists();
        Cache::put('gtfs_has_data', $has, $has ? 3600 : 60);

        return $has;
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
