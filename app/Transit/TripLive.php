<?php

namespace App\Transit;

use Carbon\CarbonImmutable;

/**
 * A started trip as it runs now. Its journey is stored when it starts; this reads each ride that
 * isn't over from the same live board Travel shows (the boarding stop's, or once aboard, the stop
 * to get off at) and moves its times by the delay, or marks it cancelled. So the trip screen,
 * Today's card, the board's trip card, Composer and the Live Activity say what the board says.
 * Best effort: a ride the board doesn't list keeps its stored times.
 */
class TripLive
{
    /** A board's run this close to the ride's timetable time is the ride. */
    private const SAME_RUN_SECONDS = 120;

    public function __construct(private readonly NearbyStation $boards) {}

    /**
     * @param  array<string, mixed>  $journey
     * @return array<string, mixed>
     */
    public function apply(array $journey, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $legs = $journey['legs'] ?? null;
        if (! is_array($legs)) {
            return $journey;
        }

        $shift = 0;
        foreach ($legs as $i => $leg) {
            if (! is_array($leg) || ! isset($leg['depart_at'], $leg['arrive_at'])) {
                continue;
            }
            $depart = CarbonImmutable::parse($leg['scheduled_depart_at'] ?? $leg['depart_at']);
            $arrive = CarbonImmutable::parse($leg['scheduled_arrive_at'] ?? $leg['arrive_at']);
            $rides = ($leg['mode'] ?? 'walk') !== 'walk' && ($leg['mode'] ?? '') !== 'bike' && filled($leg['line'] ?? null);

            // Each ride runs on its own time: the next tram doesn't wait because the first was late.
            if ($rides) {
                $shift = 0;
            }
            if ($rides && $arrive->gte($now->subMinutes(2))) {
                // Not aboard yet: the boarding stop's board. Aboard: the board where it stops next for them.
                $aboard = $depart->lt($now->subMinute());
                $run = $aboard
                    ? $this->run((string) ($leg['to']['name'] ?? ''), (string) $leg['line'], $arrive)
                    : $this->run((string) ($leg['from']['name'] ?? ''), (string) $leg['line'], $depart);
                if ($run !== null) {
                    $shift = (bool) ($run['cancelled'] ?? false) ? 0 : max(0, (int) ($run['delay'] ?? 0));
                    $legs[$i]['delay_min'] = $shift;
                    $legs[$i]['cancelled'] = (bool) ($run['cancelled'] ?? false);
                    $legs[$i]['realtime'] = ($run['time_basis'] ?? null) === 'realtime';
                }
            }

            // A late ride moves itself and what comes after it (the walk on, the get-off, the arrival).
            if ($shift > 0) {
                foreach (['depart' => $depart, 'arrive' => $arrive] as $end => $at) {
                    $moved = $at->addMinutes($shift);
                    $legs[$i]["{$end}_at"] = $moved->toIso8601String();
                    $legs[$i]["{$end}_time"] = $moved->setTimezone(config('app.timezone'))->format('H:i');
                }
            }
        }

        $journey['legs'] = $legs;
        $last = end($legs);
        if (is_array($last) && isset($last['arrive_at'])) {
            $journey['arrive_at'] = $last['arrive_at'];
            $journey['arrive_time'] = $last['arrive_time'] ?? ($journey['arrive_time'] ?? null);
        }

        return $journey;
    }

    /**
     * The board's run of a line at a stop nearest a timetable time, within a couple of minutes.
     *
     * @return array<string, mixed>|null
     */
    private function run(string $stop, string $line, CarbonImmutable $planned): ?array
    {
        $board = $this->board($stop);
        $best = null;
        foreach ($board['lines'] ?? [] as $row) {
            if ((string) ($row['line'] ?? '') !== $line) {
                continue;
            }
            foreach ($row['dirs'] ?? [] as $dir) {
                foreach ([$dir['next'] ?? null, ...($dir['later'] ?? [])] as $run) {
                    if (! is_array($run) || ! isset($run['scheduled_at'])) {
                        continue;
                    }
                    $gap = abs(CarbonImmutable::parse($run['scheduled_at'])->getTimestamp() - $planned->getTimestamp());
                    if ($gap <= self::SAME_RUN_SECONDS && ($best === null || $gap < $best[0])) {
                        $best = [$gap, $run];
                    }
                }
            }
        }

        return $best[1] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function board(string $stop): ?array
    {
        $city = (string) config('geocoding.search_default.city', 'Köln');
        $name = trim(preg_replace('/^'.preg_quote($city, '/').'\s+/u', '', trim($stop)) ?? $stop);
        if ($name === '') {
            return null;
        }

        try {
            return $this->boards->named($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
