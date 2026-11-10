<?php

namespace App\Http\Resources\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Getting there for the iPhone: the planner's ways (TakeMeThereController's answer), each with its
 * fit to the start, and what's good to know: the ticket, the weather on the way, lifts out, notices
 * on its lines.
 */
class JourneyPlanResource extends JsonResource
{
    public static $wrap = null;

    private static function how(mixed $how): ?string
    {
        if (is_string($how) && $how !== '') {
            return $how;
        }
        $labels = is_array($how) ? array_values(array_filter(array_map(fn ($shop) => is_array($shop) ? ($shop['label'] ?? null) : null, $how))) : [];

        return $labels === [] ? null : 'Buy it in the '.$labels[0].'.';
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $answer = $this->resource['answer'];
        $starts = $this->resource['starts_at'];
        $journeys = array_values(array_filter($answer['journeys'] ?? [], fn ($journey) => is_array($journey) && isset($journey['arrive_at'])));
        $ticket = $answer['ticket'] ?? null;
        $hours = $answer['conditions']['weather']['hours'] ?? [];
        $wet = collect($hours)->filter(fn ($hour) => ($hour['precip_probability'] ?? 0) >= 50)->first();

        return [
            /** What I'm on my way names a way by, with its place in `ways`. */
            'plan' => (string) $this->resource['plan'],
            'from' => (string) ($answer['from']['name'] ?? 'Your location'),
            'to' => (string) ($answer['to']['name'] ?? ''),
            /** @var array<int, array{leave_time: string, arrive_time: string, spare_min: int, status: string, mode: string, lines: array<int, array{line: string, color: string}>, departs: string|null, from: string|null, walk_min: int, transfers: int, minutes: int, in_plan: bool, live: bool, legs: array<int, array{mode: string, line: string|null, color: string|null, headsign: string|null, from: string, to: string, depart_at: string, arrive_at: string, depart_time: string, arrive_time: string, minutes: int, stops: int|null, stops_on_the_way: array<int, array{name: string, time: string, lat: float|null, lng: float|null}>, polyline: string|null, turns: array<int, array{direction: string, street: string, metres: int, polyline: string|null, level_change: bool}>}>}> */
            'ways' => array_map(function (array $journey) use ($starts) {
                $spare = $starts === null ? 0 : (int) floor((CarbonImmutable::parse($starts)->getTimestamp() - CarbonImmutable::parse($journey['arrive_at'])->getTimestamp()) / 60);

                return AppJourney::way($journey, $spare, $starts === null ? 'fits' : ($spare < 0 ? 'late' : ($spare < 3 ? 'tight' : 'fits')));
            }, $journeys),
            /** @var array{covered: bool, label: string|null, price: float|null, how: string|null}|null */
            'ticket' => is_array($ticket) ? [
                'covered' => (bool) ($ticket['covered_by_deutschlandticket'] ?? false),
                'label' => $ticket['label'] ?? null,
                'price' => isset($ticket['price_eur']) ? (float) $ticket['price_eur'] : null,
                // Where to buy it: "Buy it in the KVB app."
                'how' => self::how($ticket['how_to_buy'] ?? null),
            ] : null,
            /** @var array{at: string, chance: int}|null */
            'rain' => is_array($wet) ? ['at' => CarbonImmutable::parse($wet['at'])->setTimezone('Europe/Berlin')->format('H:i'), 'chance' => (int) $wet['precip_probability']] : null,
            /** Lifts out on the first way: "Lift to platform 2 · Neumarkt". */
            /** @var array<int, string> */
            'lifts' => array_values(array_map(fn (array $lift) => trim(($lift['label'] ?? 'Lift').' · '.AppJourney::short($lift['stop'] ?? '')), $answer['conditions']['lifts']['by_journey'][0] ?? [])),
            /** @var array<int, array{title: string, lines: array<int, string>, major: bool}> */
            'notices' => array_values(array_map(fn (array $notice) => [
                'title' => (string) ($notice['title'] ?? ''),
                'lines' => array_values(array_map('strval', $notice['lines'] ?? [])),
                'major' => ($notice['scale'] ?? 'minor') === 'major',
            ], array_filter($answer['disruptions'] ?? [], fn ($notice) => is_array($notice) && ($notice['on'] ?? null) === 'ways'))),
        ];
    }
}
