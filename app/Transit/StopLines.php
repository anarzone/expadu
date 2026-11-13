<?php

namespace App\Transit;

use App\Services\GtfsDepartureService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * How many lines stop at each stop, by its name, from the timetable: a stop many lines serve
 * (Ebertplatz, Friesenplatz) is where riders change; one or two lines (Christophstr./Mediapark)
 * is a stop a way passes. Kept a day per timetable import. Unknown (no timetable) is null.
 */
class StopLines
{
    private const KEEP_SECONDS = 86400;

    /**
     * @param  list<string>  $names  stop names as the router gives them ("Köln Ebertplatz")
     * @return array<string, int|null>
     */
    public function count(array $names): array
    {
        $generation = Cache::get(GtfsDepartureService::GENERATION_KEY, 0);
        $out = [];
        $missing = [];
        foreach (array_unique($names) as $name) {
            $kept = Cache::get($this->key($generation, $name));
            if (is_int($kept)) {
                $out[$name] = $kept;
            } else {
                $missing[] = $name;
            }
        }
        if ($missing === []) {
            return $out;
        }

        try {
            $counts = DB::table('gtfs_stops')
                ->join('gtfs_stop_times', 'gtfs_stop_times.stop_id', '=', 'gtfs_stops.stop_id')
                ->join('gtfs_trips', 'gtfs_trips.trip_id', '=', 'gtfs_stop_times.trip_id')
                ->whereIn('gtfs_stops.stop_name', $missing)
                ->groupBy('gtfs_stops.stop_name')
                ->selectRaw('gtfs_stops.stop_name as name, count(distinct gtfs_trips.route_id) as lines')
                ->pluck('lines', 'name');
        } catch (\Throwable) {
            return $out + array_fill_keys($missing, null);
        }

        foreach ($missing as $name) {
            $lines = isset($counts[$name]) ? (int) $counts[$name] : null;
            if ($lines !== null) {
                Cache::put($this->key($generation, $name), $lines, self::KEEP_SECONDS);
            }
            $out[$name] = $lines;
        }

        return $out;
    }

    private function key(mixed $generation, string $name): string
    {
        return 'stop_lines:v1:'.$generation.':'.md5(mb_strtolower($name));
    }
}
