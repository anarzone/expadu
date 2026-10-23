<?php

namespace App\Transit;

use App\Composer\TravelEstimator;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A line's whole route through the board's station (the Line route canvas,
 * owner's "build it", 23 Oct 2026): each way's stops in order, from the
 * static timetable's most common trip that way through here, with the town
 * each stop is in, the other trams that stop there, and how long it takes
 * end to end. Each stop's own times come from its own board (LineStopController).
 * Only today's trips count, so works show as they run (16 ends at Ubierring,
 * 17 Oct to 2 Nov 2026); a line with none today has no ways. Nothing when
 * the timetable doesn't know the station or the line.
 */
class LineRoute
{
    /** Under this share of a line's busiest pattern here, a pattern is left out. */
    private const RARE = 0.05;

    /** Under this share of its busiest, a section elsewhere is a short working. */
    private const SHORT_WORKING = 0.25;

    /** How far a KVB stop may be from the timetable's stop of its name. */
    private const SIGN_METRES = 250;

    public function __construct(
        private readonly StationLinesMap $map,
        private readonly KvbApiService $kvb,
        private readonly TravelEstimator $distance,
        private readonly GtfsDepartureService $gtfs,
    ) {}

    /**
     * @return array{
     *     line: string,
     *     mode: 'tram'|'rail',
     *     ways: list<array{towards: string, minutes: int|null, stops: list<array{name: string, city: string|null, board: bool, here: bool, changes: list<string>}>}>,
     *     elsewhere: list<array{from: string, to: string}>
     * }|null  no ways when the line doesn't run here today
     */
    public function for(string $station, float $lat, float $lng, string $line): ?array
    {
        $day = GtfsDepartureService::serviceDay();
        $key = 'travel:line-route:v3:'.Cache::get(GtfsDepartureService::GENERATION_KEY, 0).':'.$day->format('Ymd').':'.md5(mb_strtolower($station).'|'.round($lat, 4).'|'.round($lng, 4).'|'.$line);

        // Cached as scalars only (arrays), for the service day (to 03:00).
        return Cache::remember($key, $day->copy()->addDay()->addHours(3), fn () => $this->build($station, $lat, $lng, $line));
    }

    private function build(string $station, float $lat, float $lng, string $line): ?array
    {
        $here = $this->map->stationStops($station, $lat, $lng);
        if ($here->isEmpty()) {
            return null;
        }
        $ids = $here->pluck('stop_id')->all();

        // The line's ways through here today: per direction its most common
        // trip, leaving out rare runs (a diversion, a short working).
        $query = DB::table('gtfs_stop_times as st')
            ->join('gtfs_trips as t', 't.trip_id', '=', 'st.trip_id')
            ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
            ->whereIn('st.stop_id', $ids)
            ->where('r.route_short_name', $line);
        $type = (clone $query)->value('r.route_type');
        $mode = $type === null ? null : StationLinesMap::mode((int) $type);
        if ($mode === null || $mode === 'bus') {
            return null;
        }
        $this->gtfs->runningToday($query, 't');
        $patterns = $query
            ->groupBy('t.direction_id', 't.trip_headsign')
            ->selectRaw('t.direction_id as direction, t.trip_headsign as headsign, count(*) as trips, min(t.trip_id) as trip_id')
            ->get();
        // Its sections today that don't come through here (16's Bonn part).
        $elsewhere = $this->elsewhere($ids, $line);
        if ($patterns->isEmpty()) {
            return ['line' => $line, 'mode' => $mode, 'ways' => [], 'elsewhere' => $elsewhere];
        }
        $often = self::RARE * (int) $patterns->max('trips');
        $chosen = [];
        foreach ($patterns as $pattern) {
            $slot = (string) ($pattern->direction ?? '-');
            if ($pattern->trips >= $often && (! isset($chosen[$slot]) || $pattern->trips > $chosen[$slot]->trips)) {
                $chosen[$slot] = $pattern;
            }
        }
        ksort($chosen);

        $runs = DB::table('gtfs_stop_times as st')
            ->join('gtfs_stops as s', 's.stop_id', '=', 'st.stop_id')
            ->whereIn('st.trip_id', array_map(fn (object $p) => $p->trip_id, $chosen))
            ->orderBy('st.trip_id')
            ->orderBy('st.stop_sequence')
            ->get(['st.trip_id', 'st.stop_id', 'st.arrival_time', 'st.departure_time', 's.stop_name', 's.stop_lat', 's.stop_lng'])
            ->groupBy('trip_id');

        $stopIds = $runs->flatten(1)->pluck('stop_id')->unique()->values()->all();
        $changes = $this->changesAt($stopIds, $line);
        $signs = $this->signs($runs->flatten(1)->unique('stop_id')->all(), $station, $here->first()->stop_name);

        $ways = [];
        foreach ($chosen as $pattern) {
            $run = $runs->get($pattern->trip_id)?->values();
            if ($run === null || $run->count() < 2) {
                continue;
            }
            $last = $run->last();
            $ways[] = [
                'towards' => $signs[$last->stop_id]['name'],
                'minutes' => self::minutesBetween((string) $run->first()->departure_time, (string) $last->arrival_time),
                'stops' => $run->map(fn (object $stop) => [
                    ...$signs[$stop->stop_id],
                    'here' => in_array($stop->stop_id, $ids, true),
                    'changes' => $changes[$stop->stop_id] ?? [],
                ])->all(),
            ];
        }

        return $ways === [] ? null : ['line' => $line, 'mode' => $mode, 'ways' => $ways, 'elsewhere' => $elsewhere];
    }

    /**
     * The line's other sections today, end to end, each once whichever way
     * it runs, leaving out short workings.
     *
     * @param  list<string>  $ids  the station's stops
     * @return list<array{from: string, to: string}>
     */
    private function elsewhere(array $ids, string $line): array
    {
        $query = DB::table('gtfs_trips as t')
            ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
            ->where('r.route_short_name', $line)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('gtfs_stop_times as st')
                ->whereColumn('st.trip_id', 't.trip_id')
                ->whereIn('st.stop_id', $ids));
        $this->gtfs->runningToday($query, 't');
        $patterns = $query
            ->groupBy('t.direction_id', 't.trip_headsign')
            ->selectRaw('count(*) as trips, min(t.trip_id) as trip_id')
            ->get();
        $often = self::SHORT_WORKING * (int) $patterns->max('trips');
        $trips = $patterns->filter(fn (object $p) => $p->trips >= $often)->pluck('trip_id')->all();
        if ($trips === []) {
            return [];
        }

        $sections = [];
        $runs = DB::table('gtfs_stop_times as st')
            ->join('gtfs_stops as s', 's.stop_id', '=', 'st.stop_id')
            ->whereIn('st.trip_id', $trips)
            ->orderBy('st.stop_sequence')
            ->get(['st.trip_id', 's.stop_name'])
            ->groupBy('trip_id');
        foreach ($runs as $run) {
            $ends = [(string) $run->first()->stop_name, (string) $run->last()->stop_name];
            $key = implode('|', collect($ends)->sort()->all());
            $sections[$key] ??= ['from' => $ends[0], 'to' => $ends[1]];
        }

        return array_values($sections);
    }

    /**
     * The other trams that stop at each stop, often enough to change to
     * (trams only for now: owner, 23 Oct 2026).
     *
     * @param  list<string>  $stopIds
     * @return array<string, list<string>>
     */
    private function changesAt(array $stopIds, string $line): array
    {
        $query = DB::table('gtfs_stop_times as st')
            ->join('gtfs_trips as t', 't.trip_id', '=', 'st.trip_id')
            ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
            ->whereIn('st.stop_id', $stopIds);
        $this->gtfs->runningToday($query, 't');
        $rows = $query
            ->groupBy('st.stop_id', 'r.route_short_name', 'r.route_type')
            ->selectRaw('st.stop_id as stop_id, r.route_short_name as line, r.route_type as type, count(*) as trips')
            ->get()
            ->groupBy('stop_id');

        $changes = [];
        foreach ($rows as $stopId => $lines) {
            $often = self::RARE * (int) $lines->max('trips');
            $trams = $lines
                ->filter(fn (object $row) => StationLinesMap::mode((int) $row->type) === 'tram' && $row->trips >= $often)
                ->pluck('line')
                ->map(fn ($l) => trim((string) $l))
                ->reject(fn (string $l) => $l === '' || $l === $line)
                ->unique()
                ->sort(fn (string $a, string $b) => strnatcmp($a, $b))
                ->values()
                ->all();
            $changes[(string) $stopId] = $trams;
        }

        return $changes;
    }

    /**
     * Each stop's name as its sign says it and the town it's in: the feed
     * says "Bergisch Gladbach Refrath", the sign "Refrath". A KVB stop of
     * that name near it splits the two (and has a board to open); else the
     * station's own town is cut off when the name starts with it.
     *
     * @param  array<int, object>  $stops
     * @return array<string, array{name: string, city: string|null, board: bool}>
     */
    private function signs(array $stops, string $station, string $stationFeedName): array
    {
        $town = str_ends_with($stationFeedName, ' '.$station)
            ? mb_substr($stationFeedName, 0, mb_strlen($stationFeedName) - mb_strlen($station) - 1)
            : null;
        $kvb = collect($this->kvb->getStops())
            ->map(fn (array $point) => ['name' => (string) $point['name'], 'lat' => (float) $point['lat'], 'lng' => (float) $point['lng']]);

        $signs = [];
        foreach ($stops as $stop) {
            $feed = (string) $stop->stop_name;
            $lat = (float) $stop->stop_lat;
            $lng = (float) $stop->stop_lng;
            // The feed's name less the station's town: "Baumschulenweg" for
            // the sign's "Poll Baumschulenweg".
            $bare = $town !== null && str_starts_with($feed, $town.' ') ? mb_substr($feed, mb_strlen($town) + 1) : $feed;
            $match = $kvb
                ->filter(fn (array $p) => ($feed === $p['name'] || str_ends_with($feed, ' '.$p['name']) || $p['name'] === $bare || str_ends_with($p['name'], ' '.$bare) || str_starts_with($bare, $p['name'].' '))
                    && abs($p['lat'] - $lat) < 0.004 && abs($p['lng'] - $lng) < 0.006
                    && 1000 * $this->distance->haversineKm($lat, $lng, $p['lat'], $p['lng']) <= self::SIGN_METRES)
                ->sortByDesc(fn (array $p) => mb_strlen($p['name']))
                ->first();

            if ($match !== null) {
                $city = str_ends_with($feed, $match['name'])
                    ? trim(mb_substr($feed, 0, mb_strlen($feed) - mb_strlen($match['name'])))
                    : ($bare !== $feed ? $town : '');
                $signs[(string) $stop->stop_id] = ['name' => $match['name'], 'city' => $city === '' ? null : $city, 'board' => true];
            } elseif ($town !== null && str_starts_with($feed, $town.' ')) {
                $signs[(string) $stop->stop_id] = ['name' => mb_substr($feed, mb_strlen($town) + 1), 'city' => $town, 'board' => false];
            } else {
                $signs[(string) $stop->stop_id] = ['name' => $feed, 'city' => null, 'board' => false];
            }
        }

        return $signs;
    }

    /** Minutes from one GTFS clock ("25:10:00" runs past midnight) to another. */
    private static function minutesBetween(string $from, string $to): ?int
    {
        $minutes = fn (string $clock) => preg_match('/^(\d+):(\d{2})/', $clock, $m) ? 60 * (int) $m[1] + (int) $m[2] : null;
        $a = $minutes($from);
        $b = $minutes($to);

        return $a === null || $b === null || $b < $a ? null : $b - $a;
    }
}
