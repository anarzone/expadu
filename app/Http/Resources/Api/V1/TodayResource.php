<?php

namespace App\Http\Resources\Api\V1;

use App\Services\KvbLineColors;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Today for the iPhone, from the services the web's Today reads. Only what the app draws:
 * the weather, the day's plans and when to leave for the next, the notes that need the person,
 * a few ideas, and what's coming up. Each part is null or empty when there's nothing real to show.
 *
 * @property-read array{day: array<string, mixed>, needs: array<string, mixed>|null, discovery: array<string, mixed>, coming_up: array<string, mixed>, weather: array<string, mixed>|null} $resource
 */
class TodayResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $day = $this->resource['day'];
        $weather = $this->resource['weather'];
        $tomorrow = $this->resource['coming_up']['tomorrow'] ?? ['date' => null, 'plans' => []];

        return [
            'date' => (string) $day['date'],
            'now' => (string) $day['now'],
            /** @var array{temperature: int, condition: string, icon: string}|null */
            'weather' => ($weather['available'] ?? false) ? [
                'temperature' => (int) round((float) $weather['temperature']),
                'condition' => (string) $weather['condition'],
                'icon' => (string) $weather['icon'],
            ] : null,
            /** @var array<int, array{id: string, title: string, subtitle: string|null, start_time: string|null, end_time: string|null, place: string|null, state: string, completed: bool}> */
            'plans' => array_map(fn (array $item) => [
                'id' => (string) $item['id'],
                'title' => (string) $item['title'],
                'subtitle' => $item['subtitle'] ?? null,
                'start_time' => $item['start_time'] ?? null,
                'end_time' => $item['end_time'] ?? null,
                'place' => $item['location']['label'] ?? null,
                'state' => (string) ($item['state'] ?? 'upcoming'),
                'completed' => (bool) ($item['completed'] ?? false),
            ], $day['items'] ?? []),
            /** @var array{to: string, start_time: string|null, status: string, leave_time: string|null, leave_in_min: int|null, arrive_time: string|null, spare_min: int|null, line: string|null, color: string|null, departs: string|null, from: string|null, live: bool}|null */
            'leave' => $this->leave($day['next_trip'] ?? null),
            /** @var array<int, array{id: string, kind: string, severity: string, headline: string, detail: string|null}> */
            'needs' => array_map(fn (array $need) => [
                'id' => (string) $need['id'],
                'kind' => (string) $need['kind'],
                'severity' => (string) $need['severity'],
                'headline' => (string) $need['headline'],
                'detail' => $need['detail'] ?? null,
            ], array_slice($this->resource['needs']['items'] ?? [], 0, 3)),
            /** @var array<int, array{id: int, title: string, meta: string, category: string, emoji: string|null, starts_at: string|null, price: string|null, place: string|null, area: string|null}> */
            'ideas' => array_map(fn (array $event) => [
                'id' => (int) $event['id'],
                'title' => (string) $event['title'],
                'meta' => (string) ($event['meta'] ?? ''),
                'category' => (string) ($event['category_label'] ?? ''),
                'emoji' => $event['emoji'] ?? null,
                'starts_at' => $event['occurrence_start'] ?? null,
                'price' => $event['price_text'] ?? null,
                'place' => $event['venue']['name'] ?? null,
                'area' => $event['venue']['veedel'] ?? null,
            ], array_slice($this->resource['discovery']['events'] ?? [], 0, 6)),
            'ideas_part' => (string) ($this->resource['discovery']['part'] ?? ''),
            /** @var array{date: string|null, plans: array<int, array{title: string, place: string|null, start: string|null}>} */
            'tomorrow' => [
                'date' => $tomorrow['date'] ?? null,
                'plans' => array_map(fn (array $plan) => [
                    'title' => (string) $plan['title'],
                    'place' => $plan['location'] ?? null,
                    'start' => $plan['start'] ?? null,
                ], $tomorrow['plans'] ?? []),
            ],
        ];
    }

    /**
     * When to leave for the next plan, and the line that takes them: the Leave card.
     *
     * @param  array<string, mixed>|null  $trip
     * @return array<string, mixed>|null
     */
    private function leave(?array $trip): ?array
    {
        if ($trip === null) {
            return null;
        }
        $ride = collect($trip['journey']['legs'] ?? [])->first(fn ($leg) => is_array($leg) && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true));
        $line = is_array($ride) ? ($ride['line'] ?? null) : null;

        return [
            'to' => (string) ($trip['target']['title'] ?? ''),
            'start_time' => $trip['target']['start_time'] ?? null,
            'status' => (string) ($trip['status'] ?? 'unavailable'),
            'leave_time' => $trip['leave_time'] ?? null,
            'leave_in_min' => isset($trip['leave_in_min']) ? (int) $trip['leave_in_min'] : null,
            'arrive_time' => $trip['arrive_time'] ?? null,
            'spare_min' => isset($trip['spare_min']) ? (int) $trip['spare_min'] : null,
            'line' => $line,
            'color' => $line === null ? null : KvbLineColors::for((string) $line, $ride['mode'] ?? null),
            // When the line itself goes, and from where: "Rudolfplatz", the city's name aside.
            'departs' => is_array($ride) ? ($ride['depart_time'] ?? null) : null,
            'from' => is_array($ride) && isset($ride['from']['name']) ? trim((string) preg_replace('/^(Köln|Koeln)\s+/u', '', (string) $ride['from']['name'])) : null,
            'live' => ($trip['time_basis'] ?? null) === 'live',
        ];
    }
}
