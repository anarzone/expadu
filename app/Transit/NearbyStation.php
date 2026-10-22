<?php

namespace App\Transit;

use App\Composer\TravelEstimator;
use App\Services\DisruptionService;
use App\Services\KvbApiService;
use App\Services\LocationContext;
use App\Services\TimetableService;
use Carbon\CarbonImmutable;

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
     * A station by its KVB name (the stop picker, a favourite, `?stop=`).
     * Null when no KVB station has that name.
     *
     * @return array<string, mixed>|null
     */
    public function named(string $name): ?array
    {
        $points = $this->kvb->stationPoints($name);
        if ($points === []) {
            return null;
        }
        $areas = $this->areas($points);

        // Each area is its own stop in the feed: read each, keep its own lines.
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

        // Someone's looking: DELFI's cancellations are read while they are.
        $this->delfi->markWanted();
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
     * destination and timetabled time, among the times the board shows, and
     * never over a run the board lists. A direction the board has no row for
     * gets one for the next hour's.
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
                    if ($at->lt($now) || $at->gt($now->addHour())) {
                        continue;
                    }
                    $dest = self::place((string) $call['headsign']);
                    $key = $call['line'].'|'.$dest;
                    $rows[$key] ??= ['line' => $call['line'], 'destination' => $dest, 'type' => $area['mode'] === 'bus' ? 'bus' : 'tram',
                        'minutes' => [], 'services' => [], 'delay' => 0, 'cancelled' => true, 'disrupted' => false, 'direction' => null, 'via' => []];
                } else {
                    $times = collect($rows[$key]['services'] ?? [])
                        ->map(fn (array $s) => CarbonImmutable::parse($s['scheduled_at'] ?? $s['departure_at'])->getTimestamp());
                    $from = $times->min() ?? $now->getTimestamp();
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
                    'expected_at' => null, 'time_basis' => 'realtime', 'delay' => 0, 'cancelled' => true];
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
        $lines = $this->lines($rows, $areas);

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
            'notices' => $this->notices(array_column($lines, 'line')),
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
     * @return list<array<string, mixed>>
     */
    private function lines(array $rows, array $areas): array
    {
        $byLine = [];
        foreach ($rows as $row) {
            $services = array_values(array_filter($row['services'] ?? [], fn ($s) => is_array($s) && isset($s['departure_at'])));
            $runs = array_map(fn (array $s) => $this->service($s), $services);
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
     * @param  array<string, mixed>  $service
     * @return array<string, mixed>
     */
    private function service(array $service): array
    {
        $at = CarbonImmutable::parse($service['departure_at']);

        return [
            'id' => (string) ($service['id'] ?? sha1($service['departure_at'])),
            'at' => $at->setTimezone('Europe/Berlin')->toIso8601String(),
            'time' => $at->setTimezone('Europe/Berlin')->format('H:i'),
            'minutes' => max(0, (int) floor(($at->getTimestamp() - CarbonImmutable::now()->getTimestamp()) / 60)),
            'scheduled_at' => isset($service['scheduled_at']) ? CarbonImmutable::parse($service['scheduled_at'])->setTimezone('Europe/Berlin')->toIso8601String() : null,
            'delay' => (int) ($service['delay'] ?? 0),
            'cancelled' => (bool) ($service['cancelled'] ?? false),
            'time_basis' => (string) ($service['time_basis'] ?? 'scheduled'),
        ];
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
     * Published notices that name this station's lines.
     *
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private function notices(array $lines): array
    {
        return collect($this->disruptions->getLineDisruptions())
            ->filter(fn (array $d) => array_intersect(array_map('strval', $d['affected_lines'] ?? []), $lines) !== [])
            ->map(fn (array $d) => [
                'id' => $d['id'],
                'title' => $d['title'],
                'severity' => $d['severity'],
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
