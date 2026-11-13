<?php

namespace App\Transit;

use App\Transit\Dto\GeoPoint;
use Carbon\CarbonImmutable;

/**
 * What a rider can take on each ride of a way, as Google and Apple show it. The router keeps one run per
 * connection, so a way says "12 then 16" where the 18 does the same job ("16 / 18"), and it plans a
 * change with a minute to spare without saying so. For every stretch a way rides (stop to stop) this
 * reads the direct runs once, then gives each ride:
 *
 * - `also`: other lines on that stretch that make the same connection, soonest first: they leave once
 *   the rider can be at the stop and get there no later (the last ride: up to two minutes later);
 * - `change` (every ride after the first): the minutes to spare for the change, and when that's a
 *   minute or less, the next run on the stretch, how much later it makes them, and on the last ride
 *   when it gets them there.
 *
 * Best effort: a stretch the engine doesn't answer leaves its rides as they were.
 */
class RideChoices
{
    /** Stretches read for one plan at most, the first ways' first. */
    private const MAX_STRETCHES = 8;

    /** Lines named beside a ride at most. */
    private const MAX_ALSO = 3;

    /** A change with this many minutes or fewer to spare is tight. */
    public const TIGHT_SPARE_MINUTES = 1;

    /** The last ride's other lines may get there this much later than the way says. */
    private const LAST_RIDE_SLACK_MINUTES = 2;

    /** Runs leaving more than this before or after the way's own aren't the same choice. */
    private const AROUND_MINUTES = 10;

    public function __construct(
        private readonly MotisAdapter $motis,
        private readonly TransitousAdapter $transitous,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $journeys  as the planner sends them (Journey::toArray)
     * @return list<array<string, mixed>>
     */
    public function add(array $journeys, float $budgetSeconds = 3.0, ?CarbonImmutable $now = null): array
    {
        $engine = $this->engine();
        if ($engine === null || $budgetSeconds < 1.0) {
            return $journeys;
        }
        $now ??= CarbonImmutable::now();

        // Each stretch once, read from twenty minutes before its first ride (never before now).
        $stretches = [];
        foreach ($journeys as $journey) {
            foreach ($this->rides($journey) as $leg) {
                $key = $this->stretch($leg);
                $from = CarbonImmutable::parse($leg['depart_at'])->subMinutes(20)->max($now);
                if (isset($stretches[$key])) {
                    $stretches[$key]['at'] = $stretches[$key]['at']->min($from);
                } elseif (count($stretches) < self::MAX_STRETCHES) {
                    $stretches[$key] = [
                        'from' => new GeoPoint((float) $leg['from']['lat'], (float) $leg['from']['lng']),
                        'to' => new GeoPoint((float) $leg['to']['lat'], (float) $leg['to']['lng']),
                        'at' => $from,
                    ];
                }
            }
        }
        if ($stretches === []) {
            return $journeys;
        }

        $read = array_combine(array_keys($stretches), $engine->withTimeBudget($budgetSeconds)->directRides(array_values($stretches)));

        foreach ($journeys as $j => $journey) {
            $legs = $journey['legs'];
            $rideIndexes = array_keys($this->rides($journey));
            foreach ($rideIndexes as $k => $i) {
                $runs = $read[$this->stretch($legs[$i])] ?? [];
                if ($runs === []) {
                    continue;
                }
                $last = $k === count($rideIndexes) - 1;
                $ready = $this->ready($legs, $i, $k === 0, $now);
                $legs[$i]['also'] = $this->also($legs[$i], $runs, $ready, $last);
                if ($k > 0) {
                    $legs[$i]['change'] = $this->change($legs[$i], $runs, $ready, $last ? $journey : null);
                }
            }
            $journeys[$j]['legs'] = $legs;
        }

        return $journeys;
    }

    /**
     * When the rider can be at a ride's stop: for the first ride, now plus the walk to it; for a
     * later one, when the way gets them to its stop (off the ride before, and across on foot).
     *
     * @param  array<int, array<string, mixed>>  $legs
     */
    private function ready(array $legs, int $index, bool $first, CarbonImmutable $now): CarbonImmutable
    {
        if ($first) {
            $walk = 0;
            for ($i = 0; $i < $index; $i++) {
                $walk += (int) ($legs[$i]['duration_min'] ?? 0);
            }

            return $now->addMinutes($walk);
        }

        return CarbonImmutable::parse($legs[$index - 1]['arrive_at']);
    }

    /**
     * @param  array<string, mixed>  $leg
     * @param  list<array<string, mixed>>  $runs
     * @return list<array{line: string, color: string|null, depart_at: string, depart_time: string, arrive_time: string}>
     */
    private function also(array $leg, array $runs, CarbonImmutable $ready, bool $last): array
    {
        $leaves = CarbonImmutable::parse($leg['depart_at']);
        $arrives = CarbonImmutable::parse($leg['arrive_at'])->addMinutes($last ? self::LAST_RIDE_SLACK_MINUTES : 0);
        $own = (string) ($leg['line'] ?? '');

        return collect($runs)
            ->filter(fn (array $run) => ! $run['cancelled'] && $run['line'] !== '' && $run['line'] !== $own
                && CarbonImmutable::parse($run['depart_at'])->gte($ready->max($leaves->subMinutes(self::AROUND_MINUTES)))
                && CarbonImmutable::parse($run['depart_at'])->lte($leaves->addMinutes(self::AROUND_MINUTES))
                && CarbonImmutable::parse($run['arrive_at'])->lte($arrives))
            ->sortBy('depart_at')
            ->unique('line')
            ->take(self::MAX_ALSO)
            ->map(fn (array $run) => [
                'line' => $run['line'],
                'color' => $run['color'],
                'depart_at' => $run['depart_at'],
                'depart_time' => $this->time($run['depart_at']),
                'arrive_time' => $this->time($run['arrive_at']),
            ])
            ->values()
            ->all();
    }

    /**
     * The change onto a ride: minutes to spare, and when it's tight, the next run should it be missed.
     *
     * @param  array<string, mixed>  $leg
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>|null  $journey  the way, when this is its last ride
     * @return array{spare_min: int, tight: bool, next: array{line: string, color: string|null, depart_time: string, arrive_time: string}|null, there_time: string|null, later_min: int|null}
     */
    private function change(array $leg, array $runs, CarbonImmutable $ready, ?array $journey): array
    {
        $leaves = CarbonImmutable::parse($leg['depart_at']);
        $spare = (int) floor(($leaves->getTimestamp() - $ready->getTimestamp()) / 60);
        $tight = $spare <= self::TIGHT_SPARE_MINUTES;

        $next = ! $tight ? null : collect($runs)
            ->filter(fn (array $run) => ! $run['cancelled'] && $run['line'] !== ''
                && CarbonImmutable::parse($run['depart_at'])->gt($leaves))
            ->sortBy('depart_at')
            ->first();

        // On the last ride the rest of the way is the walk at the end, as long as it was; before it, the
        // next run's lateness carries on (a guess: the rides after may wait less or more).
        $there = null;
        $later = null;
        if ($next !== null && $journey !== null) {
            $walkOn = CarbonImmutable::parse($leg['arrive_at'])->diffInSeconds(CarbonImmutable::parse($journey['arrive_at']));
            $at = CarbonImmutable::parse($next['arrive_at'])->addSeconds((int) $walkOn);
            $there = $this->time($at->toIso8601String());
            $later = (int) round(CarbonImmutable::parse($journey['arrive_at'])->diffInSeconds($at) / 60);
        } elseif ($next !== null) {
            $later = (int) round($leaves->diffInSeconds(CarbonImmutable::parse($next['depart_at'])) / 60);
        }

        return [
            'spare_min' => max(0, $spare),
            'tight' => $tight,
            'next' => $next === null ? null : [
                'line' => $next['line'],
                'color' => $next['color'],
                'depart_time' => $this->time($next['depart_at']),
                'arrive_time' => $this->time($next['arrive_at']),
            ],
            'there_time' => $there,
            'later_min' => $later,
        ];
    }

    /**
     * A way's rides by their place in its legs; none on foot or by bike.
     *
     * @param  array<string, mixed>  $journey
     * @return array<int, array<string, mixed>>
     */
    private function rides(array $journey): array
    {
        return array_filter($journey['legs'] ?? [], fn ($leg) => is_array($leg)
            && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true)
            && isset($leg['from']['lat'], $leg['to']['lat'], $leg['depart_at'], $leg['arrive_at']));
    }

    /** A stretch as a rider names it: from this stop to that one. @param  array<string, mixed>  $leg */
    private function stretch(array $leg): string
    {
        return mb_strtolower(trim((string) ($leg['from']['name'] ?? '')).'|'.trim((string) ($leg['to']['name'] ?? '')));
    }

    private function time(string $at): string
    {
        return CarbonImmutable::parse($at)->setTimezone(config('app.timezone'))->format('H:i');
    }

    private function engine(): ?TransitousAdapter
    {
        return match (true) {
            filled(config('services.motis.url')) => $this->motis,
            filled(config('services.transitous.url')) => $this->transitous,
            default => null,
        };
    }
}
