<?php

namespace App\Transit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The cancellations VRS leaves out, from gtfs.de's free DELFI feed (CC BY-SA
 * 4.0). VRS's TRIAS drops a cancelled trip from its board and VRS's GTFS-RT
 * carries no KVB lines; DELFI marks the stops a trip won't serve (SKIPPED),
 * or the whole trip (CANCELED), by gtfs.de's own ids.
 *
 * - import(): the city's slice of gtfs.de's static timetable (delfi_calls),
 *   so a skipped stop gets its line, place, destination and timetabled time.
 * - fetch(): one read of the realtime feed (about 30 MB), only while Travel
 *   was looked at in the last ten minutes; the city's cancelled calls are
 *   kept as one small JSON value for five minutes.
 * - near(): the cancelled calls at a station, for its board.
 *
 * Nothing is inferred: a call is cancelled only when the feed says so.
 */
class DelfiCancellations
{
    /** Set whenever a Travel board is read; fetch() runs only while it's there. */
    public const WANTED_KEY = 'delfi:wanted';

    public const CANCELLED_KEY = 'delfi:cancelled:v1';

    /** The static feed last imported: its ETag and Last-Modified. */
    public const VERSION_KEY = 'delfi:static_version';

    /** The credit the board shows when it carries one of these (CC BY-SA 4.0). */
    public const CREDIT = [
        'for' => 'Cancellations',
        'name' => 'gtfs.de (DELFI)',
        'url' => 'https://gtfs.de',
        'licence' => 'CC BY-SA 4.0',
        'licence_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
    ];

    private const WANTED_SECONDS = 600;

    private const CANCELLED_SECONDS = 300;

    /** How near a skipped stop must be to one of the station's points. */
    private const NEAR_METRES = 250;

    private const SKIPPED = 1;

    private const CANCELED = 3;

    public function markWanted(): void
    {
        Cache::put(self::WANTED_KEY, 1, self::WANTED_SECONDS);
    }

    public function wanted(): bool
    {
        return Cache::has(self::WANTED_KEY);
    }

    /**
     * The static feed's version as gtfs.de publishes it now, or null when it
     * can't be read.
     *
     * @return array{etag: ?string, last_modified: ?string}|null
     */
    public function publishedVersion(): ?array
    {
        try {
            $response = Http::timeout(20)->head((string) config('services.gtfsde.static_url'));
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? [
            'etag' => $response->header('ETag') ?: null,
            'last_modified' => $response->header('Last-Modified') ?: null,
        ] : null;
    }

    /**
     * Replace the city's slice from a downloaded static zip. The old slice
     * stays readable until the new one is in.
     *
     * @return int the calls kept
     */
    public function import(string $zip): int
    {
        [$south, $west, $north, $east] = config('services.gtfsde.area');
        $open = function (string $file) use ($zip) {
            $handle = fopen("zip://{$zip}#{$file}", 'r');
            if ($handle === false) {
                throw new RuntimeException("gtfs.de: {$file} is missing from the feed");
            }

            return $handle;
        };

        // The area's stops.
        $stops = [];
        $file = $open('stops.txt');
        $columns = array_flip(fgetcsv($file, escape: '') ?: []);
        while (($row = fgetcsv($file, escape: '')) !== false) {
            $lat = (float) ($row[$columns['stop_lat']] ?? 0);
            $lng = (float) ($row[$columns['stop_lon']] ?? 0);
            if ($lat > $south && $lat < $north && $lng > $west && $lng < $east) {
                $stops[$row[$columns['stop_id']]] = [round($lat, 6), round($lng, 6)];
            }
        }
        fclose($file);

        // Their calls: 2 GB for all of Germany, read line by line, so the
        // columns are read by position (trip_id, arrival, departure, stop_id,
        // stop_sequence, stop_headsign) as gtfs.de writes them. Twice: first
        // for which trips call here, then to keep the calls with their line.
        $calls = function () use ($open, $stops): \Generator {
            $file = $open('stop_times.txt');
            $header = str_getcsv(trim((string) fgets($file)), escape: '');
            if (array_slice($header, 0, 6) !== ['trip_id', 'arrival_time', 'departure_time', 'stop_id', 'stop_sequence', 'stop_headsign']) {
                throw new RuntimeException('gtfs.de: stop_times.txt has new columns: '.implode(',', $header));
            }
            while (($line = fgets($file)) !== false) {
                $cut = explode(',', $line, 5);
                if (isset($cut[3], $stops[$cut[3]])) {
                    yield str_getcsv(rtrim($line), escape: '');
                }
            }
            fclose($file);
        };

        $trips = [];
        foreach ($calls() as $row) {
            $trips[$row[0]] = '';
        }

        // Their lines.
        $file = $open('trips.txt');
        $columns = array_flip(fgetcsv($file, escape: '') ?: []);
        while (($row = fgetcsv($file, escape: '')) !== false) {
            if (isset($trips[$row[$columns['trip_id']]])) {
                $trips[$row[$columns['trip_id']]] = $row[$columns['route_id']];
            }
        }
        fclose($file);
        $routes = [];
        $file = $open('routes.txt');
        $columns = array_flip(fgetcsv($file, escape: '') ?: []);
        while (($row = fgetcsv($file, escape: '')) !== false) {
            $routes[$row[$columns['route_id']]] = $row[$columns['route_short_name']];
        }
        fclose($file);

        DB::transaction(function () use ($calls, $stops, $trips, $routes) {
            DB::table('delfi_calls')->delete();
            $chunk = [];
            foreach ($calls() as $row) {
                $line = $routes[$trips[$row[0]] ?? ''] ?? '';
                if ($line === '') {
                    continue;
                }
                [$hours, $minutes, $seconds] = array_map('intval', explode(':', $row[2] ?: $row[1]));
                $chunk[] = [
                    'trip_id' => $row[0],
                    'stop_sequence' => (int) $row[4],
                    'line' => mb_substr($line, 0, 16),
                    'headsign' => ($row[5] ?? '') !== '' ? mb_substr($row[5], 0, 255) : null,
                    'lat' => $stops[$row[3]][0],
                    'lng' => $stops[$row[3]][1],
                    'departs' => $hours * 3600 + $minutes * 60 + $seconds,
                ];
                if (count($chunk) === 1000) {
                    DB::table('delfi_calls')->insert($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                DB::table('delfi_calls')->insert($chunk);
            }
        });

        return DB::table('delfi_calls')->count();
    }

    /**
     * One read of the realtime feed: the area's cancelled calls, kept for
     * five minutes. Null when the feed couldn't be read (the last ones expire).
     */
    public function fetch(): ?int
    {
        try {
            $response = Http::timeout(60)->connectTimeout(10)->get((string) config('services.gtfsde.realtime_url'));
        } catch (\Throwable $e) {
            Log::warning('gtfs.de realtime: fetch failed', ['message' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            Log::warning('gtfs.de realtime: fetch failed', ['status' => $response->status()]);

            return null;
        }

        $known = array_flip(DB::table('delfi_calls')->distinct()->pluck('trip_id')->all());
        $skipped = array_intersect_key(self::skipped($response->body()), $known);

        $cancelled = [];
        $now = CarbonImmutable::now('Europe/Berlin');
        foreach (array_chunk(array_keys($skipped), 1000) as $trips) {
            $calls = DB::table('delfi_calls')->whereIn('trip_id', array_map('strval', $trips))->get();
            foreach ($calls as $call) {
                $trip = $skipped[$call->trip_id];
                if (! $trip['all'] && ! isset($trip['stops'][$call->stop_sequence])) {
                    continue;
                }
                // GTFS's service day: noon minus twelve hours, then the offset.
                $at = CarbonImmutable::parse(($trip['date'] ?? $now->format('Ymd')).' 12:00', 'Europe/Berlin')
                    ->subHours(12)->addSeconds((int) $call->departs);
                if ($at->lt($now->subHours(2)) || $at->gt($now->addHours(6))) {
                    continue;
                }
                $cancelled[] = [
                    'id' => 'delfi:'.sha1($call->trip_id.'|'.($trip['date'] ?? '').'|'.$call->stop_sequence),
                    'line' => $call->line,
                    'headsign' => $call->headsign,
                    'at' => $at->toIso8601String(),
                    'lat' => (float) $call->lat,
                    'lng' => (float) $call->lng,
                ];
            }
        }

        Cache::put(self::CANCELLED_KEY, json_encode($cancelled), self::CANCELLED_SECONDS);

        return count($cancelled);
    }

    /**
     * The cancelled calls at a station's points, on the given lines.
     *
     * @param  list<array{lat: float|string, lng: float|string}>  $points
     * @param  list<string>  $lines
     * @return list<array{id: string, line: string, headsign: ?string, at: string}>
     */
    public function near(array $points, array $lines): array
    {
        $all = json_decode((string) Cache::get(self::CANCELLED_KEY, '[]'), true);
        if (! is_array($all) || $all === []) {
            return [];
        }

        $near = fn (array $call) => collect($points)->contains(fn (array $p) => self::metres((float) $p['lat'], (float) $p['lng'], $call['lat'], $call['lng']) <= self::NEAR_METRES);

        return array_values(array_map(
            fn (array $call) => ['id' => $call['id'], 'line' => $call['line'], 'headsign' => $call['headsign'], 'at' => $call['at']],
            array_filter($all, fn ($call) => is_array($call) && in_array((string) $call['line'], $lines, true) && $near($call)),
        ));
    }

    /**
     * The feed's skipped calls by trip: the stops' sequence numbers, or the
     * whole trip, and its service day. A raw walk over the protobuf: only the
     * trip descriptors and stop updates are read (a full decode of the 30 MB
     * feed takes most of a minute and half a gigabyte).
     *
     * @return array<string, array{all: bool, date: ?string, stops: array<int, true>}>
     */
    public static function skipped(string $feed): array
    {
        $trips = [];
        foreach (self::fields($feed, 0, strlen($feed)) as $field => $entity) {
            if ($field !== 2 || $entity[0] !== 2) {
                continue;
            }
            foreach (self::fields($feed, $entity[1], $entity[2]) as $part => $update) {
                if ($part !== 3 || $update[0] !== 2) {
                    continue;
                }
                $trip = null;
                $date = null;
                $all = false;
                $stops = [];
                foreach (self::fields($feed, $update[1], $update[2]) as $key => $value) {
                    if ($key === 1 && $value[0] === 2) {
                        foreach (self::fields($feed, $value[1], $value[2]) as $k => $v) {
                            match (true) {
                                $k === 1 && $v[0] === 2 => $trip = substr($feed, $v[1], $v[2] - $v[1]),
                                $k === 3 && $v[0] === 2 => $date = substr($feed, $v[1], $v[2] - $v[1]),
                                $k === 4 && $v[0] === 0 => $all = $v[1] === self::CANCELED,
                                default => null,
                            };
                        }
                    } elseif ($key === 2 && $value[0] === 2) {
                        $sequence = null;
                        $relation = 0;
                        foreach (self::fields($feed, $value[1], $value[2]) as $k => $v) {
                            if ($k === 1 && $v[0] === 0) {
                                $sequence = $v[1];
                            } elseif ($k === 5 && $v[0] === 0) {
                                $relation = $v[1];
                            }
                        }
                        if ($relation === self::SKIPPED && $sequence !== null) {
                            $stops[$sequence] = true;
                        }
                    }
                }
                if ($trip !== null && ($all || $stops !== [])) {
                    $trips[$trip] = ['all' => $all, 'date' => $date, 'stops' => $stops];
                }
            }
        }

        return $trips;
    }

    /**
     * A message's fields: number => [wire type, value] for a varint, or
     * [2, start, end] for bytes and messages.
     *
     * @return \Generator<int, array{0: int, 1: int, 2?: int}>
     */
    private static function fields(string $bytes, int $at, int $end): \Generator
    {
        while ($at < $end) {
            $key = self::varint($bytes, $at);
            $wire = $key & 7;
            if ($wire === 0) {
                yield $key >> 3 => [0, self::varint($bytes, $at)];
            } elseif ($wire === 2) {
                $length = self::varint($bytes, $at);
                yield $key >> 3 => [2, $at, $at + $length];
                $at += $length;
            } elseif ($wire === 1) {
                $at += 8;
            } elseif ($wire === 5) {
                $at += 4;
            } else {
                throw new RuntimeException("gtfs.de realtime: wire type {$wire} isn't GTFS-RT");
            }
        }
    }

    private static function varint(string $bytes, int &$at): int
    {
        $value = 0;
        $shift = 0;
        do {
            $byte = ord($bytes[$at++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $value;
    }

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $x = deg2rad($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $y = deg2rad($lat2 - $lat1);

        return sqrt($x * $x + $y * $y) * 6371000;
    }
}
