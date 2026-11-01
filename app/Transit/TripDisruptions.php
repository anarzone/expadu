<?php

namespace App\Transit;

use App\Services\DisruptionService;
use App\Services\KvbApiService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\Leg;
use Carbon\CarbonImmutable;

/**
 * What Plan a trip says about delays and disruptions for the ways it lists,
 * in two tiers (the delays canvas the owner agreed, 22 Oct 2026):
 *
 * - notices on a line the ways ride, and major ones on a line serving the
 *   From or To stop too (the ways may avoid a split line that is the obvious
 *   way there: that's why there are no 1, 7 or 9 in them);
 * - live trouble on the lines the ways ride, at the stop they board them,
 *   as the station board works it out (NearbyStation::trouble);
 * - runs the feeds say are cancelled that would have come before a way's
 *   first ride, so each stays where it was, struck through.
 *
 * Major or minor is the notice's own (NoticeScale); nothing is inferred here.
 */
final class TripDisruptions
{
    /** A KVB stop this near From or To is the one you'd start or end at. */
    private const STOP_METRES = 400;

    /** Boarding stops whose boards are read, at most (each is a live-times call). */
    private const BOARDS = 2;

    public function __construct(
        private readonly DisruptionService $disruptions,
        private readonly KvbApiService $kvb,
    ) {}

    /**
     * @param  list<Journey>  $journeys
     * @param  bool  $boards  whether there's time left to read the boarding stops' boards
     * @return array{notices: list<array<string, mixed>>, trouble: list<array<string, mixed>>, gone: list<array<string, mixed>>}
     */
    public function for(array $journeys, GeoPoint $from, GeoPoint $to, bool $boards = true): array
    {
        $ridden = collect($journeys)->flatMap(fn (Journey $j) => $j->lines())->map(fn ($l) => (string) $l)->unique()->values()->all();
        $ends = array_values(array_unique([...$this->linesAt($from), ...$this->linesAt($to)]));

        $notices = collect($this->disruptions->getLineDisruptions())
            ->map(function (array $d) use ($ridden, $ends) {
                $lines = array_map('strval', $d['affected_lines'] ?? []);
                $rides = array_intersect($lines, $ridden) !== [];
                $major = ($d['scale'] ?? 'minor') === 'major';

                return $rides || ($major && array_intersect($lines, $ends) !== [])
                    ? [
                        'id' => $d['id'],
                        'title' => $d['title'] ?? '',
                        'severity' => $d['severity'] ?? 'minor',
                        // A major line problem (the headline) or an everyday one.
                        'scale' => $major ? 'major' : 'minor',
                        // On a line the ways ride, or only on one serving From or To.
                        'on' => $rides ? 'ways' : 'ends',
                        'lines' => $lines,
                        'source' => $d['source'] ?? null,
                        'since' => $d['started_at'] ?? null,
                        // KVB's notice in plain English, when one is written; else its
                        // own words (German), and its page either way.
                        'brief' => $d['brief'] ?? null,
                        'details' => ($d['description'] ?? '') !== '' ? $d['description'] : null,
                        'url' => $d['url'] ?? null,
                    ]
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        [$trouble, $gone] = $boards ? $this->atBoardingStops($journeys, $ridden) : [[], []];

        return ['notices' => $notices, 'trouble' => $trouble, 'gone' => $gone];
    }

    /**
     * The one major problem on a trip's lines, for Leave now (Travel and
     * Today): a major notice on a line it rides, else that line in trouble
     * at the stop it boards, by its live runs. Null when there's none.
     *
     * @return array{notice: array<string, mixed>|null, trouble: array<string, mixed>|null}|null
     */
    public function forTrip(Journey $journey, bool $boards = true): ?array
    {
        $ridden = array_values(array_unique(array_map('strval', $journey->lines())));
        if ($ridden === []) {
            return null;
        }

        $notice = collect($this->disruptions->getLineDisruptions())
            ->first(fn (array $d) => ($d['scale'] ?? 'minor') === 'major'
                && array_intersect(array_map('strval', $d['affected_lines'] ?? []), $ridden) !== []);
        if ($notice !== null) {
            return ['notice' => [
                'id' => $notice['id'],
                'title' => $notice['title'] ?? '',
                'lines' => array_values(array_map('strval', $notice['affected_lines'] ?? [])),
                'source' => $notice['source'] ?? null,
                'since' => $notice['started_at'] ?? null,
                'brief' => $notice['brief'] ?? null,
                'details' => ($notice['description'] ?? '') !== '' ? $notice['description'] : null,
                'url' => $notice['url'] ?? null,
            ], 'trouble' => null];
        }

        [$trouble] = $boards ? $this->atBoardingStops([$journey], $ridden) : [[], []];

        return $trouble === [] ? null : ['notice' => null, 'trouble' => $trouble[0]];
    }

    /**
     * The lines KVB lists at the stop nearest a point, when one is near.
     *
     * @return list<string>
     */
    private function linesAt(GeoPoint $point): array
    {
        $stop = $this->kvb->nearestStop($point->lat, $point->lng);
        if ($stop === null || self::metres($point->lat, $point->lng, (float) $stop['lat'], (float) $stop['lng']) > self::STOP_METRES) {
            return [];
        }

        return array_values(array_unique(array_map('strval', array_merge(...array_column($this->kvb->stationPoints((string) $stop['name']), 'lines')))));
    }

    /**
     * Each way's first ride, read at its stop's board: that line's trouble
     * there, and its cancelled runs from ten minutes ago up to the one the
     * way takes.
     *
     * @param  list<Journey>  $journeys
     * @param  list<string>  $ridden
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function atBoardingStops(array $journeys, array $ridden): array
    {
        $firsts = collect($journeys)
            ->map(fn (Journey $j) => collect($j->legs)->first(fn (Leg $leg) => $leg->isTransit() && $leg->lineName))
            ->filter()
            ->values();
        $stops = $firsts->map(fn (Leg $leg) => self::place($leg->from->name))->filter()->unique()->take(self::BOARDS)->values();

        $trouble = [];
        $gone = [];
        $since = CarbonImmutable::now()->subMinutes(10);
        foreach ($stops as $stop) {
            $station = app(NearbyStation::class)->named($stop);
            if ($station === null) {
                continue;
            }
            foreach ($station['trouble'] ?? [] as $t) {
                if (in_array((string) $t['line'], $ridden, true)) {
                    $trouble[$t['line'].'|'.$t['dest']] ??= [...$t, 'stop' => $station['name']];
                }
            }
            foreach ($firsts as $leg) {
                if (self::place($leg->from->name) !== $stop) {
                    continue;
                }
                $takes = ($leg->scheduledDepartAt ?? $leg->departAt)->getTimestamp();
                foreach ($station['lines'] as $line) {
                    if ((string) $line['line'] !== (string) $leg->lineName) {
                        continue;
                    }
                    foreach ($line['dirs'] as $dir) {
                        if (! self::sameWay((string) $dir['dest'], (string) $leg->headsign)) {
                            continue;
                        }
                        foreach (array_filter([$dir['next'] ?? null, ...($dir['later'] ?? [])]) as $run) {
                            $at = CarbonImmutable::parse($run['at']);
                            if (($run['cancelled'] ?? false) && $at->getTimestamp() < $takes && $at->gte($since)) {
                                $gone[$run['id']] ??= ['id' => $run['id'], 'line' => (string) $line['line'], 'mode' => $line['mode'],
                                    'dest' => $dir['dest'], 'time' => $run['time'], 'at' => $run['at'], 'stop' => $station['name']];
                            }
                        }
                    }
                }
            }
        }

        return [array_values($trouble), array_values(collect($gone)->sortBy('at')->all())];
    }

    /** A stop's name as KVB has it: "Köln Appellhofplatz" → "Appellhofplatz". */
    private static function place(string $name): string
    {
        return trim((string) preg_replace('/^(Köln|Koeln)\s+/u', '', trim($name)));
    }

    /** One destination named two ways, the shorter whole within the longer. */
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

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $x = deg2rad($lng2 - $lng1) * cos(deg2rad(($lat1 + $lat2) / 2));
        $y = deg2rad($lat2 - $lat1);

        return sqrt($x * $x + $y * $y) * 6371000;
    }
}
