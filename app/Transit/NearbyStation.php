<?php

namespace App\Transit;

use App\Composer\TravelEstimator;
use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\LocationContext;
use App\Services\TimetableService;
use App\Services\VrsRealtimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Travel's Nearby: one station by boarding area, each line with where it goes
 * both ways, via which stops and when (owner-approved Travel design, 10–16
 * Oct 2026).
 *
 * Boarding areas come from KVB's own point records: points of the station
 * that share a line belong together (Neumarkt: 1·7·9, 3·4·16·18, 136·146).
 * The feed doesn't say which point a departure uses, so a line belongs to its
 * area, never to one point, and where to stand stays unknown ("Platform not
 * known"; the OpenStreetMap close-ups are deferred, plan call #9).
 *
 * Every time keeps its source: live, scheduled or paused (stale) comes from
 * the board's own flags, per departure.
 */
class NearbyStation
{
    /** A run with fewer than this many recorded departures says later ones aren't recorded. */
    private const LATER_SHOWN = 2;

    private const WALK_KMH = 4.5;

    /**
     * A built station is kept whole: fresh this many seconds, then served
     * (its gone runs dropped, marked stale) while it's rebuilt after the
     * response, for up to the second. A read of a kept one is a cache read.
     */
    private const KEPT_SECONDS = [15, 900];

    /** A kept station this old says its times are stale. */
    private const KEPT_STALE_SECONDS = 90;

    /** Cancelled runs one way in the next hour that put a line in trouble (and at least half). */
    private const TROUBLE_CANCELLED = 2;

    /** Minutes late that count towards a line in trouble. */
    private const TROUBLE_LATE_MIN = 15;

    /** @var array{keys: array<string, string>, trips: array<string, string>, lines: list<array<string, mixed>>}|null */
    private ?array $noticeIndex = null;

    /** @var list<string> notices a departure points to, listed with the station's own */
    private array $referenced = [];

    /** @var array<string, array{line: string, mode: string, dest: string, runs: list<array<string, mixed>>}> each line's runs by direction, for trouble() */
    private array $byDirection = [];

    public function __construct(
        private readonly TimetableService $timetable,
        private readonly KvbApiService $kvb,
        private readonly DisruptionService $disruptions,
        private readonly TravelEstimator $distance,
        private readonly DelfiCancellations $delfi,
    ) {}

    /**
     * The station closest to where the person is, said as honestly as we
     * know it: "Closest" only from a fix of the last few minutes; from an
     * older one it's near their last location (`located`), with no walk
     * time worked out from somewhere they may have left.
     *
     * @return array<string, mixed>|null
     */
    public function closestTo(LocationContext $location): ?array
    {
        if (! $location->hasOrigin()) {
            return null;
        }
        $station = $this->closest((float) $location->lat, (float) $location->lng);
        if ($station === null) {
            return null;
        }
        $fresh = $location->isFresh();

        return [
            ...$station,
            'walk_min' => $fresh ? $station['walk_min'] : null,
            'located' => [
                'fresh' => $fresh,
                'source' => $location->source->value,
                // What it's near, when that's a place or area, not the person.
                'label' => $location->label,
                'at' => $location->at === null ? null : CarbonImmutable::createFromTimestamp($location->at, config('app.timezone'))->toIso8601String(),
            ],
        ];
    }

    /**
     * The closest station to a point, with the walk to its nearest point.
     *
     * @return array<string, mixed>|null
     */
    public function closest(float $lat, float $lng): ?array
    {
        $nearest = $this->kvb->nearestStop($lat, $lng);
        if ($nearest === null) {
            return null;
        }
        $km = $this->distance->haversineKm($lat, $lng, (float) $nearest['lat'], (float) $nearest['lng']);
        $station = $this->named((string) $nearest['name']);

        return $station === null ? null : [
            ...$station,
            'kind' => 'closest',
            'walk_min' => max(1, (int) ceil($km / self::WALK_KMH * 60)),
        ];
    }

    /**
     * Builds a station when none is kept, so its first view is a cache read
     * (a favourite or the place you are, after a page's response).
     */
    public function warm(string $name): void
    {
        if (! Cache::has(self::keptKey($name))) {
            $this->named($name);
        }
    }

    /**
     * Whether a station reads without asking live services: kept whole, or
     * every one of its boards kept (fresh or not, ~25–80 ms to assemble).
     */
    public function ready(string $name): bool
    {
        if (Cache::has(self::keptKey($name))) {
            return true;
        }
        $points = $this->kvb->stationPoints($name);

        return $points !== [] && collect($this->areas($points))->every(fn (array $a) => $this->timetable->hasBoard((float) $a['lat'], (float) $a['lng']));
    }

    /**
     * A kept station as it is now, never built or refreshed here (Today's
     * Check departures; under a millisecond to a few); null when none is kept.
     *
     * @return array<string, mixed>|null
     */
    public function kept(string $name): ?array
    {
        $station = Cache::get(self::keptKey($name));
        if (! is_array($station)) {
            return null;
        }
        $this->reading();

        return self::rolledForward($station);
    }

    /**
     * A station by its KVB name (the stop picker, a favourite, `?stop=`).
     * Null when no KVB station has that name. Kept whole (KEPT_SECONDS).
     *
     * @return array<string, mixed>|null
     */
    public function named(string $name): ?array
    {
        $this->reading();
        $station = Cache::flexible(self::keptKey($name), self::KEPT_SECONDS, fn () => $this->build($name));

        return is_array($station) ? self::rolledForward($station) : null;
    }

    /** Someone is reading departures: DELFI's cancellations and VRS's delays are kept fresh while they are. */
    private function reading(): void
    {
        $this->delfi->markWanted();
        app(VrsRealtimeService::class)->markWanted();
    }

    private static function keptKey(string $name): string
    {
        return 'nearby_station:v1:'.Cache::get(GtfsDepartureService::GENERATION_KEY, 0).':'.md5(mb_strtolower(trim($name)));
    }

    /**
     * A kept station as of now: the runs already gone dropped (a cancelled one
     * stays its ten minutes), each way's next being its first left, and an
     * old one said to be stale.
     *
     * @param  array<string, mixed>  $station
     * @return array<string, mixed>
     */
    private static function rolledForward(array $station): array
    {
        $now = time();
        $built = strtotime((string) ($station['generated_at'] ?? '')) ?: $now;
        if ($now - $built < 5) {
            return $station;
        }
        $keep = fn (?array $run) => $run !== null && (strtotime((string) $run['at']) >= $now - 30 || ($run['cancelled'] && strtotime((string) $run['at']) >= $now - 600));
        foreach ($station['lines'] ?? [] as $l => $line) {
            foreach ($line['dirs'] ?? [] as $d => $dir) {
                $runs = array_values(array_filter([$dir['next'] ?? null, ...($dir['later'] ?? [])], $keep));
                foreach ($runs as $r => $run) {
                    $runs[$r]['minutes'] = max(0, intdiv(strtotime((string) $run['at']) - $now, 60));
                }
                $station['lines'][$l]['dirs'][$d]['next'] = $runs[0] ?? null;
                $station['lines'][$l]['dirs'][$d]['later'] = array_slice($runs, 1);
            }
        }
        if ($now - $built > self::KEPT_STALE_SECONDS) {
            $station['is_stale'] = true;
        }

        return $station;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function build(string $name): ?array
    {
        $points = $this->kvb->stationPoints($name);
        if ($points === []) {
            return null;
        }
        $areas = $this->areas($points);

        // Each area is its own stop in the feed: read each, keep its own lines.
        $this->timetable->prefetchBoards(array_map(fn (array $a) => ['lat' => (float) $a['lat'], 'lng' => (float) $a['lng']], $areas));
        $boards = [];
        $rows = [];
        foreach ($areas as $area) {
            $board = $this->timetable->pointBoard(['id' => null, 'name' => $name, 'lat' => $area['lat'], 'lng' => $area['lng']]);
            if ($board === null) {
                continue;
            }
            $boards[] = $board;
            foreach ($board['departures'] ?? [] as $row) {
                // A point's board can reach a neighbour's departures: an area
                // keeps its own lines, and a line no area lists keeps its row.
                $listed = collect($areas)->contains(fn ($a) => in_array((string) $row['line'], $a['lines'], true));
                if ($listed && ! in_array((string) $row['line'], $area['lines'], true)) {
                    continue;
                }
                $rows[$row['line'].'|'.($row['destination'] ?? '')] ??= $row;
            }
        }

        [$rows, $credited] = $this->withCancellations($name, $rows, $areas);

        return [
            ...$this->station($name, $points, $areas, array_values($rows), $boards),
            // DELFI's licence asks for its name beside what it gave the board.
            'credits' => $credited ? [DelfiCancellations::CREDIT] : [],
        ];
    }

    /**
     * DELFI's cancelled calls at the station, which VRS's board leaves out
     * (it drops a cancelled trip). Each joins its line and direction by line,
     * destination and timetabled time, among the times the board shows (or
     * the last ten minutes'), and never over a run the board lists. A
     * direction the board has no row for gets one for the last ten minutes'
     * and the next hour's.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $areas
     * @return array{0: array<string, array<string, mixed>>, 1: bool}
     */
    private function withCancellations(string $station, array $rows, array $areas): array
    {
        $now = CarbonImmutable::now('Europe/Berlin');
        $added = false;
        foreach ($areas as $area) {
            foreach ($this->delfi->near($area['places'], array_map('strval', $area['lines'])) as $call) {
                // A trip ending here doesn't leave from here.
                if (self::sameWay($station, (string) $call['headsign'])) {
                    continue;
                }
                $at = CarbonImmutable::parse($call['at']);
                $key = collect($rows)->keys()->first(fn ($k) => (string) $rows[$k]['line'] === $call['line']
                    && self::sameWay((string) ($rows[$k]['destination'] ?? ''), (string) $call['headsign']));
                if ($key === null) {
                    if ($at->lt($now->subMinutes(10)) || $at->gt($now->addHour())) {
                        continue;
                    }
                    $dest = self::place((string) $call['headsign']);
                    $key = $call['line'].'|'.$dest;
                    $rows[$key] ??= ['line' => $call['line'], 'destination' => $dest, 'type' => $area['mode'] === 'bus' ? 'bus' : 'tram',
                        'minutes' => [], 'services' => [], 'delay' => 0, 'cancelled' => true, 'disrupted' => false, 'direction' => null, 'via' => []];
                } else {
                    $times = collect($rows[$key]['services'] ?? [])
                        ->map(fn (array $s) => CarbonImmutable::parse($s['scheduled_at'] ?? $s['departure_at'])->getTimestamp());
                    // From the earliest it shows, or ten minutes ago: a run
                    // that should have just left is the one someone waits for.
                    $from = min($times->min() ?? PHP_INT_MAX, $now->subMinutes(10)->getTimestamp());
                    $to = $times->max() ?? $now->addHour()->getTimestamp();
                    if ($at->getTimestamp() < $from || $at->getTimestamp() > $to
                        || $times->contains(fn (int $t) => abs($t - $at->getTimestamp()) < 60)) {
                        continue;
                    }
                }
                if (collect($rows[$key]['services'])->contains('id', $call['id'])) {
                    continue;
                }
                $rows[$key]['services'][] = ['id' => $call['id'], 'departure_at' => $call['at'], 'scheduled_at' => $call['at'],
                    'expected_at' => null, 'time_basis' => 'realtime', 'delay' => 0, 'cancelled' => true,
                    'trip' => $call['trip'] ?? null];
                $added = true;
            }
        }

        foreach ($rows as $key => $row) {
            if ($row['services'] ?? []) {
                usort($row['services'], fn (array $a, array $b) => strcmp(
                    CarbonImmutable::parse($a['departure_at'])->toIso8601ZuluString(),
                    CarbonImmutable::parse($b['departure_at'])->toIso8601ZuluString(),
                ));
                $row['cancelled'] = collect($row['services'])->every(fn (array $s) => (bool) ($s['cancelled'] ?? false));
                $rows[$key] = $row;
            }
        }

        return [$rows, $added];
    }

    /** The place a destination names, without the city: "Köln Merkenich" → "Merkenich". */
    private static function place(string $destination): string
    {
        return trim((string) preg_replace('/^(Köln|Koeln)\s+/u', '', trim($destination)));
    }

    /**
     * One destination named two ways, the shorter whole within the longer:
     * "Zollstock" and "Köln Zollstock Südfriedhof", "Schlebusch" and
     * "Leverkusen Schlebusch".
     */
    private static function sameWay(string $a, string $b): bool
    {
        $a = mb_strtolower(self::place($a));
        $b = mb_strtolower(self::place($b));
        if ($a === '' || $b === '') {
            return false;
        }
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];

        return str_contains(' '.$long.' ', ' '.$short.' ');
    }

    /**
     * @param  list<array<string, mixed>>  $points  the station's KVB points
     * @param  array<string, array<string, mixed>>  $areas
     * @param  list<array<string, mixed>>  $rows  departures, one per line and destination
     * @param  list<array<string, mixed>>  $boards  the area boards they came from
     * @return array<string, mixed>
     */
    private function station(string $name, array $points, array $areas, array $rows, array $boards): array
    {
        $lines = $this->lines($rows, $areas, app(GtfsDepartureService::class)->headways($name));

        // A line the KVB points don't list gets an area of its own mode.
        foreach ($lines as $i => $line) {
            if ($line['area'] === null) {
                $id = $this->slug($name).'-'.$line['mode'];
                $areas[$id] ??= ['id' => $id, 'lines' => [], 'mode' => $line['mode'], 'places' => [], 'lat' => null, 'lng' => null, 'platform' => null];
                $areas[$id]['lines'][] = $line['line'];
                $lines[$i]['area'] = $id;
            }
        }

        $stale = collect($boards)->contains(fn ($b) => (bool) ($b['is_stale'] ?? false));
        $bases = collect($boards)->pluck('time_basis')->filter()->unique();
        $observed = collect($boards)->pluck('fetched_at')->filter()->sort()->first();

        return [
            'name' => $name,
            'kind' => 'picked',
            'station' => KvbApiService::stationNumber($points[0]['id']),
            'lat' => round(array_sum(array_column($points, 'lat')) / count($points), 6),
            'lng' => round(array_sum(array_column($points, 'lng')) / count($points), 6),
            'walk_min' => null,
            'source' => collect($boards)->pluck('source')->first(fn ($s) => $s !== 'unavailable') ?? 'unavailable',
            'time_basis' => match (true) {
                $boards === [] || $bases->every(fn ($b) => $b === 'unavailable') => 'unavailable',
                $stale => 'stale',
                $bases->contains('scheduled') => 'scheduled',
                default => 'realtime',
            },
            'is_stale' => $stale,
            // The oldest reading among the areas: what "Recorded …" must say.
            'observed_at' => $observed,
            'generated_at' => CarbonImmutable::now('Europe/Berlin')->toIso8601String(),
            'areas' => array_values($areas),
            'lines' => $lines,
            'lifts' => $this->lifts($points),
            // Its lines as KVB lists them too: one that doesn't stop here now (a closure) still counts.
            'notices' => $this->notices(array_values(array_unique([...array_column($lines, 'line'),
                ...array_map('strval', array_merge(...array_column(array_values($areas), 'lines')))])), $this->referenced),
            'trouble' => $this->trouble($stale),
        ];
    }

    /**
     * Points that list a shared line form one area (union-find over the records).
     *
     * @param  list<array<string, mixed>>  $points
     * @return array<string, array<string, mixed>>
     */
    private function areas(array $points): array
    {
        $parent = array_keys($points);
        $find = function (int $i) use (&$parent, &$find): int {
            return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i]));
        };
        foreach ($points as $i => $a) {
            foreach ($points as $j => $b) {
                if ($j > $i && array_intersect($a['lines'], $b['lines']) !== []) {
                    $parent[$find($j)] = $find($i);
                }
            }
        }

        $groups = [];
        foreach ($points as $i => $point) {
            $groups[$find($i)][] = $point;
        }

        $areas = [];
        foreach ($groups as $members) {
            $lines = array_values(array_unique(array_merge(...array_column($members, 'lines'))));
            usort($lines, fn ($a, $b) => strnatcmp((string) $a, (string) $b));
            $places = [];
            foreach ($members as $member) {
                $places[sprintf('%.6f,%.6f', $member['lat'], $member['lng'])] = ['lat' => (float) $member['lat'], 'lng' => (float) $member['lng']];
            }
            $places = array_values($places);
            $id = $this->slug($members[0]['name']).'-'.implode('-', $lines);
            $areas[$id] = [
                'id' => $id,
                'lines' => $lines,
                'mode' => collect($members)->every(fn ($m) => ($m['area'] ?? '') === 'BUS') ? 'bus' : 'tram',
                'places' => $places,
                'lat' => round(array_sum(array_column($places, 'lat')) / count($places), 6),
                'lng' => round(array_sum(array_column($places, 'lng')) / count($places), 6),
                // Where to stand isn't recorded (plan call #9): "Platform not known".
                'platform' => null,
            ];
        }

        return $areas;
    }

    /**
     * The board's rows (one per line and destination) as lines, each with its
     * runs: the next departure and later ones, per direction, soonest first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $areas
     * @param  array<string, int>  $headways  usual minutes between runs, by "line|headsign" (GtfsDepartureService::headways)
     * @return list<array<string, mixed>>
     */
    private function lines(array $rows, array $areas, array $headways = []): array
    {
        $byLine = [];
        // Per station: one request can read several (a trip's boarding stops).
        $this->byDirection = [];
        $this->referenced = [];
        foreach ($rows as $row) {
            $services = array_values(array_filter($row['services'] ?? [], fn ($s) => is_array($s) && isset($s['departure_at'])));
            $runs = array_map(fn (array $s) => $this->service($s, (string) $row['line']), $services);
            $next = collect($runs)->first(fn (array $r) => ! $r['cancelled']);
            $cancelled = $runs !== [] && $next === null;
            if ($runs === [] && ($row['minutes'] ?? []) === []) {
                continue;
            }
            $line = (string) $row['line'];
            $mode = match ($row['type'] ?? '') {
                'bus' => 'bus',
                'rail' => 'rail',
                default => 'tram',
            };
            $byLine[$line] ??= ['line' => $line, 'mode' => $mode, 'area' => null, 'dirs' => []];
            $way = $line.'|'.($row['direction'] ?? $row['destination'] ?? '');
            $this->byDirection[$way] ??= ['line' => $line, 'mode' => $mode, 'dest' => (string) ($row['destination'] ?? ''), 'runs' => []];
            array_push($this->byDirection[$way]['runs'], ...$runs);
            $first = $next ?? ($runs[0] ?? null);
            $later = array_values(array_filter($runs, fn (array $r) => $first === null || $r['id'] !== $first['id']));
            // The next few that leave, and the first few cancelled ones.
            $running = array_slice(array_values(array_filter($later, fn (array $r) => ! $r['cancelled'])), 0, 3);
            $gone = array_slice(array_values(array_filter($later, fn (array $r) => $r['cancelled'])), 0, 4);
            $later = array_values(array_filter($later, fn (array $r) => in_array($r, $running, true) || in_array($r, $gone, true)));
            $byLine[$line]['dirs'][] = [
                'id' => sha1($line.'|'.($row['destination'] ?? '')),
                'dest' => (string) ($row['destination'] ?? ''),
                'via' => array_values(array_slice($row['via'] ?? [], 0, 2)),
                'direction' => $row['direction'] ?? null,
                // Older rows carry minutes only; a run is then minutes from now.
                'next' => $first ?? $this->fromMinutes($row),
                'later' => $later,
                'later_recorded' => count($runs) >= self::LATER_SHOWN,
                'cancelled' => $cancelled,
                'disrupted' => (bool) ($row['disrupted'] ?? false),
                // Its usual minutes between runs here now, by the timetable
                // (null when it doesn't say): a long wait is one beyond it.
                'headway' => collect($headways)->first(fn (int $minutes, string $key) => str_starts_with($key, $line.'|')
                    && self::sameWay((string) ($row['destination'] ?? ''), substr($key, strlen($line) + 1))),
            ];
        }

        foreach ($byLine as $key => $entry) {
            usort($entry['dirs'], fn ($a, $b) => ($a['next']['at'] ?? '') <=> ($b['next']['at'] ?? ''));
            foreach ($areas as $area) {
                if (in_array($entry['line'], array_map('strval', $area['lines']), true)) {
                    $entry['area'] = $area['id'];
                    break;
                }
            }
            $byLine[$key] = $entry;
        }

        $lines = array_values($byLine);
        usort($lines, fn ($a, $b) => strnatcmp($a['line'], $b['line']));

        return $lines;
    }

    /**
     * A line in trouble at this stop by its own runs, one way (the delays
     * canvas, 22 Oct 2026): most of the next hour's runs cancelled (at least
     * two, and all or at least half), or three in a row each 15+ min late, or every
     * run in the next 30 min (at least two) that late. Only what the feeds
     * say: lateness only from live times, none while they're paused.
     *
     * @return list<array{line: string, mode: string, dest: string, kind: 'cancelled'|'late', count: int, of: int, runs: list<array{time: string, delay: int, cancelled: bool}>}>
     */
    private function trouble(bool $stale): array
    {
        $now = CarbonImmutable::now();
        $trouble = [];
        foreach ($this->byDirection as $way) {
            $runs = collect($way['runs'])->unique('id')->sortBy('at')->values();
            $hour = $runs->filter(fn (array $r) => CarbonImmutable::parse($r['at'])->lte($now->addHour()))->values();
            $gone = $hour->where('cancelled', true);
            $brief = fn ($list) => $list->map(fn (array $r) => ['time' => $r['time'], 'delay' => $r['delay'], 'cancelled' => $r['cancelled']])->values()->all();
            if ($gone->count() >= self::TROUBLE_CANCELLED && $gone->count() * 2 >= $hour->count()) {
                $trouble[] = ['line' => $way['line'], 'mode' => $way['mode'], 'dest' => $way['dest'], 'kind' => 'cancelled',
                    'count' => $gone->count(), 'of' => $hour->count(), 'runs' => $brief($gone)];

                continue;
            }
            if ($stale) {
                continue;
            }
            $live = $runs->filter(fn (array $r) => ! $r['cancelled'] && $r['time_basis'] === 'realtime')->values();
            $streak = [];
            $longest = [];
            foreach ($live as $run) {
                $streak = $run['delay'] >= self::TROUBLE_LATE_MIN ? [...$streak, $run] : [];
                $longest = count($streak) > count($longest) ? $streak : $longest;
            }
            $soon = $runs->filter(fn (array $r) => ! $r['cancelled'] && CarbonImmutable::parse($r['at'])->lte($now->addMinutes(30)))->values();
            $allLate = $soon->count() >= 2 && $soon->every(fn (array $r) => $r['time_basis'] === 'realtime' && $r['delay'] >= self::TROUBLE_LATE_MIN);
            if (count($longest) >= 3 || $allLate) {
                $late = count($longest) >= 3 ? collect($longest) : $soon;
                $trouble[] = ['line' => $way['line'], 'mode' => $way['mode'], 'dest' => $way['dest'], 'kind' => 'late',
                    'count' => $late->count(), 'of' => $late->count(), 'runs' => $brief($late)];
            }
        }

        return $trouble;
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function service(array $service, string $line = ''): array
    {
        $at = CarbonImmutable::parse($service['departure_at']);
        $cancelled = (bool) ($service['cancelled'] ?? false);

        return [
            'id' => (string) ($service['id'] ?? sha1($service['departure_at'])),
            'at' => $at->setTimezone('Europe/Berlin')->toIso8601String(),
            'time' => $at->setTimezone('Europe/Berlin')->format('H:i'),
            'minutes' => max(0, (int) floor(($at->getTimestamp() - CarbonImmutable::now()->getTimestamp()) / 60)),
            'scheduled_at' => isset($service['scheduled_at']) ? CarbonImmutable::parse($service['scheduled_at'])->setTimezone('Europe/Berlin')->toIso8601String() : null,
            'delay' => (int) ($service['delay'] ?? 0),
            'cancelled' => $cancelled,
            'time_basis' => (string) ($service['time_basis'] ?? 'scheduled'),
            // The notice that explains it (its id in `notices`), or null.
            'notice' => $this->noticeFor($service, $line, $at, $cancelled),
        ];
    }

    /**
     * The open notice that explains a departure, matched in code, never
     * guessed: a VRS situation the departure itself names; for a cancelled
     * one, DELFI's alert on its own trip, else a notice on its line whose
     * time covers it (KVB's, VRS's or DELFI's). None when nothing matches.
     *
     * @param  array<string, mixed>  $service
     */
    private function noticeFor(array $service, string $line, CarbonImmutable $at, bool $cancelled): ?string
    {
        $index = $this->noticeIndex();
        foreach ((array) ($service['notices'] ?? []) as $key) {
            if (isset($index['keys'][$key])) {
                return $this->referenced[] = $index['keys'][$key];
            }
        }
        if (! $cancelled) {
            return null;
        }
        if (is_string($service['trip'] ?? null) && isset($index['trips'][$service['trip']])) {
            return $this->referenced[] = $index['trips'][$service['trip']];
        }
        foreach ($index['lines'] as $notice) {
            if (in_array($line, $notice['lines'], true) && $at->between($notice['from'], $notice['until'])) {
                return $this->referenced[] = $notice['id'];
            }
        }

        return null;
    }

    /**
     * The open notices by what a departure can match: a feed's own id, a
     * DELFI trip, a line over a time. Read once per request.
     *
     * @return array{keys: array<string, string>, trips: array<string, string>, lines: list<array{id: string, lines: list<string>, from: CarbonImmutable, until: CarbonImmutable}>}
     */
    private function noticeIndex(): array
    {
        if ($this->noticeIndex !== null) {
            return $this->noticeIndex;
        }
        $index = ['keys' => [], 'trips' => [], 'lines' => []];
        foreach ($this->disruptions->openNotices() as $notice) {
            $id = 'news_'.$notice->id;
            $index['keys'][(string) $notice->source_url] = $id;
            foreach ((array) ($notice->affected_trips ?? []) as $trip) {
                $index['trips'][(string) $trip] = $id;
            }
            $lines = array_map('strval', (array) ($notice->affected_lines ?? []));
            if ($lines !== []) {
                // A live KVB notice's published_at is when it was last seen; it started when first seen.
                $from = CarbonImmutable::parse($notice->source === 'kvb-betriebslage' ? $notice->created_at : ($notice->published_at ?? $notice->created_at));
                $index['lines'][] = ['id' => $id, 'lines' => $lines, 'from' => $from,
                    'until' => $notice->expires_at !== null ? CarbonImmutable::parse($notice->expires_at) : $from->addDays(30)];
            }
        }

        return $this->noticeIndex = $index;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function fromMinutes(array $row): ?array
    {
        $minutes = $row['minutes'][0] ?? null;
        if (! is_numeric($minutes)) {
            return null;
        }
        $at = CarbonImmutable::now('Europe/Berlin')->addMinutes((int) $minutes)->startOfMinute();

        return ['id' => sha1(($row['line'] ?? '').'|'.($row['destination'] ?? '').'|'.$minutes), 'at' => $at->toIso8601String(),
            'time' => $at->format('H:i'), 'minutes' => (int) $minutes, 'scheduled_at' => null, 'delay' => (int) ($row['delay'] ?? 0),
            'cancelled' => false, 'time_basis' => 'scheduled'];
    }

    /**
     * Lifts out of order at this station, from KVB's lift feed. Which platform
     * a lift serves isn't in the feed, so it's the station's, not an area's.
     *
     * @param  list<array<string, mixed>>  $points
     * @return array{known: bool, out: list<array{id: string, label: string, since: string|null}>}
     */
    private function lifts(array $points): array
    {
        $known = $this->kvb->knownElevatorDisruptions();
        if ($known === null || $points === []) {
            return ['known' => false, 'out' => []];
        }
        $station = KvbApiService::stationNumber($points[0]['id']);

        return [
            'known' => true,
            'out' => array_values(array_map(
                fn (array $lift) => ['id' => $lift['id'], 'label' => $lift['label'], 'since' => $lift['timestamp'] ?: null],
                array_filter($known, fn (array $lift) => (int) $lift['stop_area'] === $station),
            )),
        ];
    }

    /**
     * Published notices that name this station's lines, and any notice one
     * of its departures points to.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $referenced
     * @return list<array<string, mixed>>
     */
    private function notices(array $lines, array $referenced = []): array
    {
        return collect($this->disruptions->getLineDisruptions())
            ->filter(fn (array $d) => array_intersect(array_map('strval', $d['affected_lines'] ?? []), $lines) !== []
                || in_array($d['id'], $referenced, true))
            ->map(fn (array $d) => [
                'id' => $d['id'],
                'title' => $d['title'],
                'severity' => $d['severity'],
                // Major (a headline) or minor (small, in place), and why (NoticeScale).
                'scale' => $d['scale'] ?? 'minor',
                'reasons' => $d['scale_reasons'] ?? [],
                'lines' => array_values(array_map('strval', $d['affected_lines'] ?? [])),
                'source' => $d['source'] ?? null,
                'since' => $d['started_at'] ?? null,
                // KVB's notice in plain English, when one is written; else its
                // own words (German), and its page either way.
                'brief' => $d['brief'] ?? null,
                'details' => ($d['description'] ?? '') !== '' ? $d['description'] : null,
                'url' => $d['url'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function slug(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
    }
}
