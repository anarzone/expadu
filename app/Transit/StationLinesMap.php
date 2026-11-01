<?php

namespace App\Transit;

use App\Composer\TravelEstimator;
use App\Services\GtfsDepartureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Travel's "Lines and stops" map (departures-next/transit-map.js), for any
 * station: each tram and train line through it, with the next stops each way
 * and where it ends, from the static timetable (gtfs_*). Each way is the
 * line's most common trip in that direction today, so works show as they
 * run (17 not drawn while it doesn't run). Buses are listed, not drawn.
 * Nothing when the timetable isn't loaded or doesn't know the station.
 */
class StationLinesMap
{
    /** Stops shown each way before the line's end. */
    private const NEXT = 2;

    /** How far from the board's station a timetable stop of that name may be. */
    private const NEAR_METRES = 400;

    /** Under this share of the busiest line's runs here, a line each way is left out. */
    private const RARE = 0.05;

    public function __construct(
        private readonly TravelEstimator $distance,
        private readonly GtfsDepartureService $gtfs,
    ) {}

    /**
     * @return array{
     *     station: array{name: string, lat: float, lng: float},
     *     lines: list<array{line: string, mode: 'tram'|'rail', ways: list<array{towards: string, stops: list<array{name: string, lat: float, lng: float}>, end: array{name: string, lat: float, lng: float}|null, between: int}>}>,
     *     buses: list<string>
     * }|null
     */
    public function for(string $name, float $lat, float $lng): ?array
    {
        $day = GtfsDepartureService::serviceDay();
        $key = 'travel:lines-map:v3:'.Cache::get(GtfsDepartureService::GENERATION_KEY, 0).':'.$day->format('Ymd').':'.md5(mb_strtolower($name).'|'.round($lat, 4).'|'.round($lng, 4));

        // Cached as scalars only (arrays), for the service day (to 03:00).
        return Cache::remember($key, $day->copy()->addDay()->addHours(3), fn () => $this->build($name, $lat, $lng));
    }

    /**
     * The timetable's stops for the board's station: its name, near where the
     * board says it is (a name alone would find Neumarkt in other towns too).
     *
     * @return Collection<int, object{stop_id: string, stop_name: string, stop_lat: string, stop_lng: string}>
     */
    public function stationStops(string $name, float $lat, float $lng): Collection
    {
        return DB::table('gtfs_stops')
            ->where('location_type', 0)
            ->where('stop_name', 'ILIKE', '%'.addcslashes($name, '%_\\').'%')
            ->whereBetween('stop_lat', [$lat - 0.005, $lat + 0.005])
            ->whereBetween('stop_lng', [$lng - 0.008, $lng + 0.008])
            ->get(['stop_id', 'stop_name', 'stop_lat', 'stop_lng'])
            ->filter(fn (object $stop) => 1000 * $this->distance->haversineKm($lat, $lng, (float) $stop->stop_lat, (float) $stop->stop_lng) <= self::NEAR_METRES)
            ->values();
    }

    private function build(string $name, float $lat, float $lng): ?array
    {
        $stops = $this->stationStops($name, $lat, $lng);

        if ($stops->isEmpty()) {
            return null;
        }

        // The feed's names carry the city ("Köln Neumarkt"); the signs don't.
        $prefix = '';
        foreach ($stops as $stop) {
            if (str_ends_with($stop->stop_name, ' '.$name)) {
                $prefix = mb_substr($stop->stop_name, 0, mb_strlen($stop->stop_name) - mb_strlen($name));
                break;
            }
        }
        $sign = fn (string $stopName) => $prefix !== '' && str_starts_with($stopName, $prefix)
            ? mb_substr($stopName, mb_strlen($prefix))
            : $stopName;

        $ids = $stops->pluck('stop_id')->all();

        // Each line's most common way through today, per direction.
        $query = DB::table('gtfs_stop_times as st')
            ->join('gtfs_trips as t', 't.trip_id', '=', 'st.trip_id')
            ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
            ->whereIn('st.stop_id', $ids);
        $this->gtfs->runningToday($query, 't');
        $patterns = $query
            ->groupBy('r.route_short_name', 'r.route_type', 't.direction_id', 't.trip_headsign')
            ->selectRaw('r.route_short_name as line, r.route_type as type, t.direction_id as direction, t.trip_headsign as headsign, count(*) as trips, min(t.trip_id) as trip_id')
            ->get();

        // How often each line runs here each way, all its patterns together.
        $runsHere = [];
        foreach ($patterns as $pattern) {
            $slot = trim((string) $pattern->line).'|'.($pattern->direction ?? '-');
            $runsHere[$slot] = ($runsHere[$slot] ?? 0) + (int) $pattern->trips;
        }
        // A diversion or a special run (16 and 18 via Friesenplatz, a few
        // times in the whole timetable) isn't the line's way through here.
        $often = self::RARE * max([0, ...$runsHere]);

        $buses = [];
        $chosen = [];
        foreach ($patterns as $pattern) {
            $line = trim((string) $pattern->line);
            $slot = $line.'|'.($pattern->direction ?? '-');
            if ($line === '' || $runsHere[$slot] < $often) {
                continue;
            }
            $mode = self::mode((int) $pattern->type);
            if ($mode === 'bus') {
                $buses[$line] = true;

                continue;
            }
            if ($mode === null) {
                continue;
            }
            if (! isset($chosen[$slot]) || $pattern->trips > $chosen[$slot]['trips']) {
                $chosen[$slot] = ['line' => $line, 'mode' => $mode, 'trips' => (int) $pattern->trips, 'trip_id' => $pattern->trip_id];
            }
        }

        $runs = DB::table('gtfs_stop_times as st')
            ->join('gtfs_stops as s', 's.stop_id', '=', 'st.stop_id')
            ->whereIn('st.trip_id', array_column($chosen, 'trip_id'))
            ->orderBy('st.trip_id')
            ->orderBy('st.stop_sequence')
            ->get(['st.trip_id', 'st.stop_id', 's.stop_name', 's.stop_lat', 's.stop_lng'])
            ->groupBy('trip_id');

        $point = fn (object $stop) => [
            'name' => $sign($stop->stop_name),
            'lat' => round((float) $stop->stop_lat, 6),
            'lng' => round((float) $stop->stop_lng, 6),
        ];

        $lines = [];
        foreach ($chosen as $way) {
            $run = $runs->get($way['trip_id'])?->values();
            $at = $run?->search(fn (object $stop) => in_array($stop->stop_id, $ids, true));
            if ($run === null || $at === false) {
                continue;
            }
            // Where it goes from here; a run that ends here goes nowhere.
            $after = $run->slice($at + 1)->values();
            if ($after->isEmpty()) {
                continue;
            }
            $last = $after->last();
            $shown = $after->take(self::NEXT)->reject(fn (object $stop) => $stop === $last)->values();

            $lines[$way['line']] ??= ['line' => $way['line'], 'mode' => $way['mode'], 'ways' => []];
            $lines[$way['line']]['ways'][] = [
                'towards' => $sign($last->stop_name),
                'stops' => $shown->map($point)->all(),
                'end' => $point($last),
                'between' => max(0, $after->count() - 1 - $shown->count()),
            ];
        }

        if ($lines === [] && $buses === []) {
            return null;
        }

        $nearest = $stops->sortBy(fn (object $stop) => $this->distance->haversineKm($lat, $lng, (float) $stop->stop_lat, (float) $stop->stop_lng))->first();
        $byLine = fn (string $a, string $b) => strnatcmp($a, $b);
        uksort($lines, $byLine);
        $buses = array_keys($buses);
        usort($buses, $byLine);

        return [
            'station' => [
                'name' => $name,
                'lat' => round((float) $nearest->stop_lat, 6),
                'lng' => round((float) $nearest->stop_lng, 6),
            ],
            'lines' => array_values($lines),
            'buses' => array_map('strval', $buses),
        ];
    }

    /** GTFS route types (basic and extended) as Travel's modes; null for the rest. */
    public static function mode(int $type): ?string
    {
        return match (true) {
            $type === 0, $type === 1, $type === 5, $type >= 900 && $type < 1000, $type >= 400 && $type < 500 => 'tram',
            $type === 2, $type >= 100 && $type < 200 => 'rail',
            $type === 3, $type >= 700 && $type < 800 => 'bus',
            default => null,
        };
    }
}
