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

    /** A planned way's rides read from boards this far ahead at most; later ones say timetable. */
    private const PLAN_AHEAD_MINUTES = 60;

    /**
     * A planned way, with each ride's live state from the board at its stop, so the routes say what the
     * board says: a ride the board lists is on time, late or cancelled; one it doesn't (or one too far
     * ahead, or with no live board) is timetable only, whatever the router thought.
     *
     * @param  array<string, mixed>  $journey
     * @return array<string, mixed>
     */
    public function confirm(array $journey, ?CarbonImmutable $now = null): array
    {
        return $this->apply($journey, $now, confirm: true);
    }

    /**
     * Strips the router's own live claim from a way's rides: the board's comes later ({@see confirm}).
     *
     * @param  array<string, mixed>  $journey
     * @return array<string, mixed>
     */
    public static function unconfirmed(array $journey): array
    {
        foreach ($journey['legs'] ?? [] as $i => $leg) {
            if (is_array($leg) && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true)) {
                $journey['legs'][$i]['realtime'] = false;
                $journey['legs'][$i]['delay_min'] = null;
            }
        }

        return $journey;
    }

    /**
     * @param  array<string, mixed>  $journey
     * @return array<string, mixed>
     */
    public function apply(array $journey, ?CarbonImmutable $now = null, bool $confirm = false): array
    {
        $now ??= CarbonImmutable::now();
        $legs = $journey['legs'] ?? null;
        if (! is_array($legs)) {
            return $journey;
        }

        // A planned way is retimed whole from the timetable plus what the boards say, so a delay only the
        // router knew of doesn't move its times; a started trip moves only for a delay.
        $shift = 0;
        $retime = false;
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
                $retime = $confirm;
            }
            if ($rides && $arrive->gte($now->subMinutes(2))) {
                // Not aboard yet: the boarding stop's board. Aboard: the board where it stops next for them.
                $aboard = $depart->lt($now->subMinute());
                $run = match (true) {
                    $confirm && $depart->gt($now->addMinutes(self::PLAN_AHEAD_MINUTES)) => null,
                    $aboard => $this->run((string) ($leg['to']['name'] ?? ''), (string) $leg['line'], $arrive),
                    default => $this->run((string) ($leg['from']['name'] ?? ''), (string) $leg['line'], $depart),
                };
                if ($run === null && $confirm) {
                    $legs[$i]['realtime'] = false;
                    $legs[$i]['delay_min'] = null;
                }
                if ($run !== null) {
                    $shift = (bool) ($run['cancelled'] ?? false) ? 0 : max(0, (int) ($run['delay'] ?? 0));
                    $legs[$i]['delay_min'] = $shift;
                    $legs[$i]['cancelled'] = (bool) ($run['cancelled'] ?? false);
                    $legs[$i]['realtime'] = ($run['time_basis'] ?? null) === 'realtime';
                }
            }

            // A late ride moves itself and what comes after it (the walk on, the get-off, the arrival).
            if ($shift > 0 || $retime) {
                foreach (['depart' => $depart, 'arrive' => $arrive] as $end => $at) {
                    $moved = $at->addMinutes($shift);
                    $legs[$i]["{$end}_at"] = $moved->toIso8601String();
                    $legs[$i]["{$end}_time"] = $moved->setTimezone(config('app.timezone'))->format('H:i');
                }
            }
        }

        [$legs, $missed] = self::changes($legs);
        unset($journey['missed']);
        if ($missed !== null) {
            $journey['missed'] = $missed;
        }

        // The walk to the first ride follows it, so leaving still means catching it.
        if ($confirm) {
            $legs = $this->walkToFirstRide($journey['legs'], $legs);
            if (is_array($legs[0] ?? null) && isset($legs[0]['depart_at'])) {
                $journey['depart_at'] = $legs[0]['depart_at'];
                $journey['depart_time'] = $legs[0]['depart_time'] ?? ($journey['depart_time'] ?? null);
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
     * Each change after a late ride, as it now is (owner's route, 19 Nov 2026: the 12 a little late, its
     * change walk at Ebertplatz moved past the 16's departure, and starting the way failed). A change walk
     * gets what's left before the next ride: a change within a stop takes seconds, whatever the timetable's
     * minimum change time says. A ride that now gets in after the next one leaves misses it: said, with
     * the lines, the stop and how late.
     *
     * @param  array<int, mixed>  $legs
     * @return array{0: array<int, mixed>, 1: array{line: string, stop: string, after: string, late_min: int}|null}
     */
    private static function changes(array $legs): array
    {
        $rides = array_keys(array_filter($legs, fn (mixed $leg): bool => is_array($leg) && isset($leg['depart_at'], $leg['arrive_at'])
            && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true) && filled($leg['line'] ?? null)));
        for ($r = 1; $r < count($rides); $r++) {
            [$a, $b] = [$rides[$r - 1], $rides[$r]];
            $in = CarbonImmutable::parse($legs[$a]['arrive_at']);
            $next = CarbonImmutable::parse($legs[$b]['depart_at']);
            if ($in->gt($next)) {
                $city = (string) config('geocoding.search_default.city', 'Köln');
                $stop = trim(preg_replace('/^'.preg_quote($city, '/').'\s+/u', '', (string) ($legs[$b]['from']['name'] ?? '')) ?? '');

                return [$legs, ['line' => (string) $legs[$b]['line'], 'stop' => $stop, 'after' => (string) $legs[$a]['line'],
                    'late_min' => (int) ($legs[$a]['delay_min'] ?? 0)]];
            }
            for ($w = $a + 1; $w < $b; $w++) {
                if (! is_array($legs[$w]) || ! isset($legs[$w]['depart_at'], $legs[$w]['arrive_at'])) {
                    continue;
                }
                foreach (['depart', 'arrive'] as $end) {
                    $at = CarbonImmutable::parse($legs[$w]["{$end}_at"]);
                    if ($at->gt($next)) {
                        $legs[$w]["{$end}_at"] = $next->toIso8601String();
                        $legs[$w]["{$end}_time"] = $next->setTimezone(config('app.timezone'))->format('H:i');
                    }
                }
                if (isset($legs[$w]['duration_min'])) {
                    $legs[$w]['duration_min'] = (int) round(CarbonImmutable::parse($legs[$w]['depart_at'])->diffInSeconds(CarbonImmutable::parse($legs[$w]['arrive_at'])) / 60);
                }
            }
        }

        return [$legs, null];
    }

    /**
     * The legs before the first ride, moved by as much as that ride moved.
     *
     * @param  array<int, mixed>  $before
     * @param  array<int, mixed>  $after
     * @return array<int, mixed>
     */
    private function walkToFirstRide(array $before, array $after): array
    {
        foreach ($after as $i => $leg) {
            if (! is_array($leg) || in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true)) {
                continue;
            }
            $moved = CarbonImmutable::parse($leg['depart_at'])->getTimestamp() - CarbonImmutable::parse($before[$i]['depart_at'])->getTimestamp();
            for ($j = 0; $j < $i && $moved !== 0; $j++) {
                foreach (['depart', 'arrive'] as $end) {
                    $at = CarbonImmutable::parse($after[$j]["{$end}_at"])->addSeconds($moved);
                    $after[$j]["{$end}_at"] = $at->toIso8601String();
                    $after[$j]["{$end}_time"] = $at->setTimezone(config('app.timezone'))->format('H:i');
                }
            }

            return $after;
        }

        return $after;
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
