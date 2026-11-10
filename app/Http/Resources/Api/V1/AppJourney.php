<?php

namespace App\Http\Resources\Api\V1;

use App\Services\KvbLineColors;
use App\Transit\Polyline;

/**
 * A journey (Journey::toArray) as the iPhone draws it, the same wherever a way shows: the Leave
 * card's Other ways and an event's Getting there.
 */
final class AppJourney
{
    /**
     * One way: when, the lines, the walking and changes, how long, and its legs.
     *
     * @param  array<string, mixed>  $journey
     * @return array<string, mixed>
     */
    public static function way(array $journey, int $spare = 0, string $status = 'fits', bool $inPlan = false, bool $live = false): array
    {
        $legs = collect($journey['legs'] ?? [])->filter(fn ($leg) => is_array($leg));
        $rides = $legs->filter(fn (array $leg) => ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true))->values();
        $ride = $rides->first();

        return [
            'leave_time' => (string) ($journey['depart_time'] ?? ''),
            'arrive_time' => (string) ($journey['arrive_time'] ?? ''),
            'spare_min' => $spare,
            'status' => $status,
            'mode' => (string) ($journey['mode'] ?? 'transit'),
            'lines' => $rides->map(fn (array $leg) => ['line' => (string) ($leg['line'] ?? ''), 'color' => KvbLineColors::for((string) ($leg['line'] ?? ''), $leg['mode'] ?? null)])->all(),
            'departs' => $ride['depart_time'] ?? null,
            'from' => isset($ride['from']['name']) ? self::short($ride['from']['name']) : null,
            'walk_min' => (int) $legs->filter(fn (array $leg) => ($leg['mode'] ?? null) === 'walk')->sum('duration_min'),
            'transfers' => max(0, $rides->count() - 1),
            'minutes' => (int) ($journey['duration_min'] ?? 0),
            'in_plan' => $inPlan,
            'live' => $live,
            // Journey details on its ticket.
            'legs' => self::legs($journey),
        ];
    }

    /**
     * A journey's legs as the app draws them: the step list, the live card and the map.
     *
     * @param  array<string, mixed>  $journey
     * @return array<int, array<string, mixed>>
     */
    public static function legs(array $journey): array
    {
        return array_values(array_map(fn (array $leg) => [
            'mode' => (string) ($leg['mode'] ?? 'walk'),
            'line' => $leg['line'] ?? null,
            'color' => isset($leg['line']) ? KvbLineColors::for((string) $leg['line'], $leg['mode'] ?? null) : null,
            'headsign' => isset($leg['headsign']) ? self::short($leg['headsign']) : null,
            'from' => self::short($leg['from']['name'] ?? null),
            'to' => self::short($leg['to']['name'] ?? null),
            'depart_at' => (string) $leg['depart_at'],
            'arrive_at' => (string) $leg['arrive_at'],
            'depart_time' => (string) ($leg['depart_time'] ?? ''),
            'arrive_time' => (string) ($leg['arrive_time'] ?? ''),
            'minutes' => (int) ($leg['duration_min'] ?? 0),
            'stops' => isset($leg['stops']) ? (int) $leg['stops'] : null,
            // The stops it passes between boarding and getting off, with when it's there: the map's
            // stop dots and the full stop list on the trip.
            'stops_on_the_way' => self::stopsOnTheWay($leg),
            // The way it goes, for the map (MOTIS's encoded polyline, precision 7).
            'polyline' => $leg['polyline'] ?? null,
            // On foot: the router's turns, for the map and the next turn while walking.
            'turns' => is_array($leg['steps'] ?? null) ? self::turns($leg['steps']) : [],
        ], array_filter($journey['legs'] ?? [], fn ($leg) => is_array($leg) && isset($leg['depart_at'], $leg['arrive_at']))));
    }

    /**
     * The router's steps as turns someone walks by: going straight on (on the same street, or one
     * with no name) adds to the turn before it, so a street is one turn, not ten.
     *
     * @param  array<int, mixed>  $steps
     * @return list<array{direction: string, street: string, metres: int, polyline: string|null, level_change: bool}>
     */
    public static function turns(array $steps): array
    {
        $turns = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $turn = [
                'direction' => (string) ($step['direction'] ?? 'continue'),
                'street' => (string) ($step['street'] ?? ''),
                'metres' => (int) ($step['metres'] ?? 0),
                'polyline' => $step['polyline'] ?? null,
                // A change of floor: a lift or stairs in a station.
                'level_change' => isset($step['from_level'], $step['to_level']) && (float) $step['from_level'] !== (float) $step['to_level'],
            ];
            $last = array_key_last($turns);
            // The router often says "continue" onto a new street: the angle between the two stretches says which way.
            if ($last !== null && $turn['direction'] === 'continue' && $turn['street'] !== '' && $turn['street'] !== $turns[$last]['street']) {
                $turn['direction'] = self::bend($turns[$last]['polyline'], $turn['polyline']) ?? 'continue';
            }
            $straightOn = $last !== null && $turn['direction'] === 'continue' && ! $turn['level_change']
                && ($turn['street'] === '' || $turn['street'] === $turns[$last]['street']);
            if ($straightOn) {
                $turns[$last]['metres'] += $turn['metres'];

                continue;
            }
            $turns[] = $turn;
        }

        return $turns;
    }

    /**
     * Which way the walk bends from one stretch into the next, by their headings: left, right,
     * slightly so, or straight on (under 30°).
     */
    private static function bend(?string $before, ?string $after): ?string
    {
        $a = $before === null ? null : Polyline::decode($before, Polyline::PRECISION);
        $b = $after === null ? null : Polyline::decode($after, Polyline::PRECISION);
        if ($a === null || $b === null || count($a) < 2 || count($b) < 2) {
            return null;
        }
        $heading = function (array $from, array $to): float {
            [$lat1, $lng1] = $from;
            [$lat2, $lng2] = $to;

            return rad2deg(atan2(($lng2 - $lng1) * cos(deg2rad($lat1)), $lat2 - $lat1));
        };
        // Over about 12 m on each side: a stretch's last metre can wobble where two ways meet.
        $metres = fn (array $p, array $q): float => hypot(($q[1] - $p[1]) * 111_320 * cos(deg2rad($p[0])), ($q[0] - $p[0]) * 111_320);
        $end = $a[count($a) - 1];
        $back = $a[count($a) - 2];
        for ($i = count($a) - 2; $i >= 0 && $metres($a[$i], $end) < 12; $i--) {
            $back = $a[$i];
        }
        $start = $b[0];
        $ahead = $b[1];
        for ($i = 1; $i < count($b) && $metres($start, $b[$i - 1]) < 12; $i++) {
            $ahead = $b[$i];
        }
        $turn = $heading($start, $ahead) - $heading($back, $end);
        $turn = fmod($turn + 540, 360) - 180;

        return match (true) {
            abs($turn) < 30 => 'continue',
            abs($turn) < 60 => $turn > 0 ? 'slightly_right' : 'slightly_left',
            abs($turn) < 150 => $turn > 0 ? 'right' : 'left',
            default => $turn > 0 ? 'uturn_right' : 'uturn_left',
        };
    }

    /** "Köln Neumarkt" as the stop signs say it: "Neumarkt". */
    /**
     * A ride's stops between boarding and getting off.
     *
     * @param  array<string, mixed>  $leg
     * @return list<array{name: string, time: string, lat: float|null, lng: float|null}>
     */
    public static function stopsOnTheWay(array $leg): array
    {
        $stops = [];
        foreach ($leg['intermediate_stops'] ?? [] as $stop) {
            if (! is_array($stop)) {
                continue;
            }
            $stops[] = [
                'name' => self::short($stop['name'] ?? null),
                'time' => (string) ($stop['arrive_time'] ?? ''),
                'lat' => isset($stop['lat']) ? (float) $stop['lat'] : null,
                'lng' => isset($stop['lng']) ? (float) $stop['lng'] : null,
            ];
        }

        return $stops;
    }

    public static function short(?string $name): string
    {
        return $name === null ? '' : trim((string) preg_replace('/^(Köln|Koeln)\s+/u', '', $name));
    }
}
