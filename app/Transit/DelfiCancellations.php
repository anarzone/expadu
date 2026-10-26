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
     * The timetable delfi_calls was imported from (in the database beside it,
     * never only a cache: a lost key stopped every live read for an hour).
     *
     * @return array{etag: ?string, last_modified: ?string}|null
     */
    public function importedVersion(): ?array
    {
        $row = DB::table('delfi_imports')->find(1);

        return $row === null ? null : ['etag' => $row->etag, 'last_modified' => $row->last_modified];
    }

    /**
     * Replace the city's slice from a downloaded static zip, and note which
     * published timetable it is (none for a zip of unknown version) in the
     * same transaction. The old slice stays readable until the new one is in.
     *
     * @param  array{etag: ?string, last_modified: ?string}|null  $version
     * @return int the calls kept
     */
    public function import(string $zip, ?array $version = null): int
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

        DB::transaction(function () use ($calls, $stops, $trips, $routes, $version) {
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
            DB::table('delfi_imports')->upsert(
                [['id' => 1, 'etag' => $version['etag'] ?? null, 'last_modified' => $version['last_modified'] ?? null, 'imported_at' => now()]],
                ['id'],
            );
        });

        return DB::table('delfi_calls')->count();
    }

    /**
     * One read of the realtime feed: the area's cancelled calls, kept for
     * five minutes. Null when the feed couldn't be read (the last ones expire).
     *
     * The feed numbers its trips by gtfs.de's newest timetable, so while the
     * imported one is older the same ids are other trips (Freiburg's line 17
     * alert landed on Cologne's line 4): then nothing is read or kept until
     * transit:import-delfi has caught up.
     */
    public function fetch(): ?int
    {
        $published = $this->publishedVersion();
        if ($published !== null && $published !== $this->importedVersion()) {
            Log::warning('gtfs.de realtime: the imported timetable is older than the published one; skipping until it is imported', ['published' => $published]);
            Cache::put(self::CANCELLED_KEY, json_encode([]), self::CANCELLED_SECONDS);

            return 0;
        }

        try {
            // gtfs.de can take most of a minute for the 26-30 MB when busy.
            $response = Http::timeout(150)->connectTimeout(10)->get((string) config('services.gtfsde.realtime_url'));
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
        $this->rememberAlerts($response->body(), $known);

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
                    // The trip and its day, as DELFI's alerts name them.
                    'trip' => self::tripKey($call->trip_id, $trip['date'] ?? null),
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
     * @return list<array{id: string, line: string, headsign: ?string, at: string, trip: ?string}>
     */
    public function near(array $points, array $lines): array
    {
        $all = json_decode((string) Cache::get(self::CANCELLED_KEY, '[]'), true);
        if (! is_array($all) || $all === []) {
            return [];
        }

        $near = fn (array $call) => collect($points)->contains(fn (array $p) => self::metres((float) $p['lat'], (float) $p['lng'], $call['lat'], $call['lng']) <= self::NEAR_METRES);

        return array_values(array_map(
            fn (array $call) => ['id' => $call['id'], 'line' => $call['line'], 'headsign' => $call['headsign'], 'at' => $call['at'], 'trip' => $call['trip'] ?? null],
            array_filter($all, fn ($call) => is_array($call) && in_array((string) $call['line'], $lines, true) && $near($call)),
        ));
    }

    /** A trip on its service day, as alerts and cancelled calls both name it. */
    public static function tripKey(string $trip, ?string $date): string
    {
        return $date !== null && $date !== '' ? "{$trip}@{$date}" : $trip;
    }

    /**
     * DELFI's alerts on the area's trips, into the notices store: their text
     * (German), the lines of the trips they're attached to, and those trips,
     * so a cancelled call shows the alert about its own trip. gtfs.de's data
     * credits and a vehicle's features ride the same channel and aren't news.
     *
     * @param  array<string, int>  $known  the area's trip ids
     */
    private function rememberAlerts(string $feed, array $known): void
    {
        $notices = app(TransitNotices::class);
        foreach (self::alerts($feed, $known) as $id => $alert) {
            $trips = $alert['trips'];
            $parts = array_filter([$alert['header'], $alert['description']], fn (string $part) => $part !== '');
            if ($trips === [] || $parts === [] || array_filter($parts, fn (string $part) => ! self::notNews($part)) === []) {
                continue;
            }
            $lines = DB::table('delfi_calls')->whereIn('trip_id', array_column($trips, 'trip'))->distinct()->pluck('line')->all();
            $notices->remember(
                'delfi:alert:'.$id,
                'delfi',
                $alert['header'],
                $alert['description'] !== $alert['header'] ? $alert['description'] : null,
                array_map('strval', $lines),
                [],
                array_map(fn (array $trip) => self::tripKey($trip['trip'], $trip['date']), $trips),
                $alert['from'] !== null ? CarbonImmutable::createFromTimestamp($alert['from']) : null,
                $alert['until'] !== null ? CarbonImmutable::createFromTimestamp($alert['until']) : null,
            );
        }
    }

    /**
     * gtfs.de's credit, or only what a vehicle is or has ("Solobus",
     * "Niederflur", "Linie S9: WLAN verfügbar").
     */
    public static function notNews(string $text): bool
    {
        // A line's own name first ("Linie S9:") says nothing on its own.
        $text = (string) preg_replace('/^\s*Linie\s+\S+\s*:\s*/iu', '', $text);

        return preg_match('/^Echtzeitdaten aufbereitet von/iu', $text) === 1
            || (mb_strlen($text) <= 80 && preg_match('/^[\s,.\-]*(?:(?:Solobus|Gelenkbus|Niederflur\w*|Rollstuhl\w*|für\s+Rollstuhlfahrer\s+geeignet|Stufenlose?s?\s+Fahrzeug|WLAN|WC|Klimaanlage|Fahrradmitnahme|Stadtbahn|Straßenbahn|Bus|Zug|verfügbar|vorhanden|an\s+Bord)[\s,.\-]*)+$/iu', $text) === 1);
    }

    /**
     * The feed's alerts by id: German header and description, the trips
     * they're attached to (with their service day; only the given ones, when
     * given: a credit names most of Germany's trips) and their period.
     *
     * @param  array<string, mixed>|null  $only  trip ids to keep
     * @return array<string, array{header: string, description: string, trips: list<array{trip: string, date: ?string}>, from: ?int, until: ?int}>
     */
    public static function alerts(string $feed, ?array $only = null): array
    {
        $alerts = [];
        $text = function (array $field) use ($feed): string {
            $out = '';
            foreach (self::fields($feed, $field[1], $field[2]) as $k => $translation) {
                if ($k !== 1 || $translation[0] !== 2) {
                    continue;
                }
                $value = '';
                $language = '';
                foreach (self::fields($feed, $translation[1], $translation[2]) as $kk => $part) {
                    if ($kk === 1 && $part[0] === 2) {
                        $value = substr($feed, $part[1], $part[2] - $part[1]);
                    } elseif ($kk === 2 && $part[0] === 2) {
                        $language = substr($feed, $part[1], $part[2] - $part[1]);
                    }
                }
                if ($out === '' || $language === 'de') {
                    $out = $value;
                }
            }

            return trim(str_replace("\u{a0}", ' ', $out));
        };

        foreach (self::fields($feed, 0, strlen($feed)) as $field => $entity) {
            if ($field !== 2 || $entity[0] !== 2) {
                continue;
            }
            $id = null;
            $alert = null;
            foreach (self::fields($feed, $entity[1], $entity[2]) as $part => $value) {
                if ($part === 1 && $value[0] === 2) {
                    $id = substr($feed, $value[1], $value[2] - $value[1]);
                } elseif ($part === 5 && $value[0] === 2) {
                    $alert = ['header' => '', 'description' => '', 'trips' => [], 'from' => null, 'until' => null];
                    foreach (self::fields($feed, $value[1], $value[2]) as $k => $v) {
                        if ($k === 1 && $v[0] === 2) {
                            foreach (self::fields($feed, $v[1], $v[2]) as $kk => $vv) {
                                if ($kk === 1 && $vv[0] === 0) {
                                    $alert['from'] = $alert['from'] === null ? $vv[1] : min($alert['from'], $vv[1]);
                                } elseif ($kk === 2 && $vv[0] === 0) {
                                    $alert['until'] = max($alert['until'] ?? 0, $vv[1]);
                                }
                            }
                        } elseif ($k === 5 && $v[0] === 2) {
                            foreach (self::fields($feed, $v[1], $v[2]) as $kk => $vv) {
                                if ($kk !== 4 || $vv[0] !== 2) {
                                    continue;
                                }
                                $trip = null;
                                $date = null;
                                foreach (self::fields($feed, $vv[1], $vv[2]) as $t => $tv) {
                                    if ($t === 1 && $tv[0] === 2) {
                                        $trip = substr($feed, $tv[1], $tv[2] - $tv[1]);
                                    } elseif ($t === 3 && $tv[0] === 2) {
                                        $date = substr($feed, $tv[1], $tv[2] - $tv[1]);
                                    }
                                }
                                if ($trip !== null && ($only === null || isset($only[$trip]))) {
                                    $alert['trips'][] = ['trip' => $trip, 'date' => $date];
                                }
                            }
                        } elseif ($k === 10 && $v[0] === 2) {
                            $alert['header'] = $text($v);
                        } elseif ($k === 11 && $v[0] === 2) {
                            $alert['description'] = $text($v);
                        }
                    }
                }
            }
            if ($id !== null && $alert !== null) {
                $alerts[$id] = $alert;
            }
        }

        return $alerts;
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
