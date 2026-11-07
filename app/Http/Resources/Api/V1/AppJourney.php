<?php

namespace App\Http\Resources\Api\V1;

use App\Services\KvbLineColors;

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
            // The way it goes, for the map (MOTIS's encoded polyline, precision 7).
            'polyline' => $leg['polyline'] ?? null,
        ], array_filter($journey['legs'] ?? [], fn ($leg) => is_array($leg) && isset($leg['depart_at'], $leg['arrive_at']))));
    }

    /** "Köln Neumarkt" as the stop signs say it: "Neumarkt". */
    public static function short(?string $name): string
    {
        return $name === null ? '' : trim((string) preg_replace('/^(Köln|Koeln)\s+/u', '', $name));
    }
}
