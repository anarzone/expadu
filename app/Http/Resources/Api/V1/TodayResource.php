<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BureaucracyPerson;
use App\Services\KvbLineColors;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Today for the iPhone, from the services the web's Today reads. Only what the app draws:
 * the weather, the day's plans and when to leave for the next, the notes that need the person,
 * ideas and places for the time they have, Paperwork's next step, what they saved and what's
 * coming up. Each part is null or empty when there's nothing real to show.
 *
 * @property-read array{day: array<string, mixed>, needs: array<string, mixed>|null, discovery: array<string, mixed>, coming_up: array<string, mixed>, paperwork: array<string, mixed>|null, saved: array<string, mixed>|null, continuation: array<string, mixed>|null, weather: array<string, mixed>|null, forecast: array<string, mixed>|null, saved_events?: array<int, string>, welcome?: bool, personalised?: bool, rank_paused?: array<string, mixed>|null, weather_days?: array<int, array<string, mixed>>} $resource
 */
class TodayResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $day = $this->resource['day'];
        $paperwork = $this->resource['paperwork'] ?? null;
        $comingUp = $this->resource['coming_up'] ?? [];
        $processes = $paperwork['processes'] ?? [];

        return [
            'date' => (string) $day['date'],
            'now' => (string) $day['now'],
            // The plan's revision a change to the day must send (day/command).
            'revision' => (int) ($day['revision'] ?? 0),
            // A first visit: the page leads with Paperwork's way in.
            'welcome' => (bool) ($this->resource['welcome'] ?? false),
            // Personalised suggestions on (Settings), for How we rank.
            'personalised' => (bool) ($this->resource['personalised'] ?? false),
            /** @var array{notice: array<int, string>, changes: array<int, string>|null, model_changed: bool, notice_version: string, provider_version: string}|null */
            'rank_paused' => $this->resource['rank_paused'] ?? null,
            /** @var array{temperature: int, condition: string, icon: string, summary: string|null, cycling: string|null, feels_like: int|null, high: int|null, low: int|null, hours: array<int, array{hour: string, temperature: int|null, icon: string|null, rain: int|null}>, today: array<int, array{hour: string, temperature: int|null, icon: string|null, condition: string|null, rain: int|null}>, days: array<int, array{date: string, low: int|null, high: int|null, icon: string|null, rain: int|null}>}|null */
            'weather' => $this->weather($this->resource['weather'] ?? null, $this->resource['forecast'] ?? null, $this->resource['weather_days'] ?? []),
            /** @var array<int, array{id: string, title: string, subtitle: string|null, start_time: string|null, end_time: string|null, place: string|null, state: string, completed: bool, editable: bool, bring: array{title: string|null, ready: int, total: int, process: string|null}|null}> */
            'plans' => array_map(function (array $item) use ($processes) {
                $process = isset($item['process_id']) ? ($processes[$item['process_id']] ?? null) : null;

                return [
                    'id' => (string) $item['id'],
                    'title' => (string) $item['title'],
                    'subtitle' => $item['subtitle'] ?? null,
                    'start_time' => $item['start_time'] ?? null,
                    'end_time' => $item['end_time'] ?? null,
                    'place' => $item['location']['label'] ?? null,
                    'state' => (string) ($item['state'] ?? 'upcoming'),
                    'completed' => (bool) ($item['completed'] ?? false),
                    // Plans the person made can be edited and removed; Paperwork's appointments can't.
                    'editable' => ($item['source'] ?? null) === 'workspace',
                    // What to bring, for an appointment from Paperwork.
                    'bring' => is_array($process['documents'] ?? null) ? [
                        'title' => $process['title'] ?? null,
                        'ready' => (int) $process['documents']['ready'],
                        'total' => (int) $process['documents']['total'],
                        'process' => $process['occurrence_key'] ?? null,
                    ] : null,
                ];
            }, $day['items'] ?? []),
            /** @var array<int, array{from_id: string, to_id: string, reason: string|null, basis: string|null, travel_min: int|null, gap_min: int|null}> */
            'links' => array_map(fn (array $link) => [
                'from_id' => (string) $link['from_id'],
                'to_id' => (string) $link['to_id'],
                'reason' => $link['reason'] ?? null,
                'basis' => $link['basis'] ?? null,
                'travel_min' => isset($link['travel_min']) ? (int) $link['travel_min'] : null,
                'gap_min' => isset($link['gap_min']) ? (int) $link['gap_min'] : null,
            ], $day['links'] ?? []),
            /** @var array{plan_id: string|null, to: string, start_time: string|null, status: string, leave_time: string|null, leave_in_min: int|null, arrive_time: string|null, spare_min: int|null, line: string|null, color: string|null, departs: string|null, from: string|null, walk_min: int|null, transfers: int|null, live: bool, ways: array<int, array{leave_time: string, arrive_time: string, spare_min: int, status: string, mode: string, lines: array<int, array{line: string, color: string}>, departs: string|null, from: string|null, walk_min: int, transfers: int, minutes: int, in_plan: bool, live: bool, legs: array<int, array{mode: string, line: string|null, color: string|null, headsign: string|null, from: string, to: string, depart_at: string, arrive_at: string, depart_time: string, arrive_time: string, minutes: int, stops: int|null, stops_on_the_way: array<int, array{name: string, time: string, lat: float|null, lng: float|null}>, polyline: string|null, turns: array<int, array{direction: string, street: string, metres: int, polyline: string|null, level_change: bool}>}>}>}|null */
            'leave' => $this->leave($day['next_trip'] ?? null),
            /** @var array{to: string, arrive_time: string|null, start_time: string|null, legs: array<int, array{mode: string, line: string|null, color: string|null, headsign: string|null, from: string, to: string, depart_at: string, arrive_at: string, depart_time: string, arrive_time: string, minutes: int, stops: int|null, stops_on_the_way: array<int, array{name: string, time: string, lat: float|null, lng: float|null}>, polyline: string|null, turns: array<int, array{direction: string, street: string, metres: int, polyline: string|null, level_change: bool}>}>}|null */
            'trip' => $this->trip($day['active_trip'] ?? null),
            /** @var array{kind: string, document: string|null, title: string, date: string, days_remaining: int, next: string|null, ready: int|null, total: int|null, process: string|null}|null */
            'deadline' => $this->deadline($paperwork['strip'] ?? null),
            /** @var array<int, array{id: string, kind: string, severity: string, headline: string, detail: string|null, line: string|null, color: string|null, alert_id: int|null}> */
            'needs' => array_map(fn (array $need) => [
                'id' => (string) $need['id'],
                'kind' => (string) $need['kind'],
                'severity' => (string) $need['severity'],
                'headline' => (string) $need['headline'],
                'detail' => $need['detail'] ?? null,
                // A disruption or delay on one of their lines: its badge, and the alert card it opens.
                'line' => ($need['line'] ?? null) === null ? null : (string) $need['line'],
                'color' => ($need['line'] ?? null) === null ? null : KvbLineColors::for((string) $need['line']),
                'alert_id' => isset($need['alert_id']) ? (int) $need['alert_id'] : null,
            ], $this->resource['needs']['items'] ?? []),
            /** @var array<int, array{id: int, candidate: string, saved: bool, why: string|null, reasons: array<int, string>, title: string, category: string, emoji: string|null, starts_at: string|null, ends_at: string|null, timed: bool, untimed: string|null, tomorrow: bool, price: string|null, place: string|null, area: string|null, language: string|null}> */
            'ideas' => array_map(fn (array $event) => $this->idea($event), array_slice($this->resource['discovery']['events'] ?? [], 0, 6)),
            'ideas_part' => (string) ($this->resource['discovery']['part'] ?? ''),
            /** @var array{anchor: array{title: string, area: string, start: string|null, end: string|null}|null, near: string|null, at: string, places: array<int, array{id: int, candidate: string, name: string, kind: string, label: string, km: float|null, open_until: string|null, lat: float|null, lng: float|null}>}|null */
            'free' => $this->free($this->resource['discovery']['around'] ?? null),
            /** @var array{process_title: string|null, title: string, ready: int|null, total: int|null, process: string|null}|null */
            'paperwork' => is_array($paperwork['focus'] ?? null) ? [
                'process_title' => $paperwork['focus']['process_title'] ?? null,
                'title' => (string) $paperwork['focus']['title'],
                'ready' => $paperwork['focus']['documents']['ready'] ?? null,
                'total' => $paperwork['focus']['documents']['total'] ?? null,
                'process' => $paperwork['focus']['link']['process'] ?? null,
            ] : null,
            /** Why the Paperwork card says what it says: next (a step to do), clear (a record, nothing to do now), paused (processing stopped), unconfirmed (email still to confirm), none (no record yet). */
            'paperwork_status' => $this->paperworkStatus($request, $paperwork),
            /** @var array{kind: string, title: string, last_at: string|null}|null */
            'continuation' => $this->resource['continuation'] ?? null,
            /** @var array{count: int, items: array<int, array{type: string, id: int, candidate: string, title: string, date: string|null, place: string|null, label: string|null, area: string|null}>}|null */
            'saved' => is_array($this->resource['saved'] ?? null) ? [
                'count' => (int) $this->resource['saved']['count'],
                'items' => array_map(fn (array $item) => [
                    'type' => (string) $item['type'],
                    'id' => (int) $item['id'],
                    'candidate' => $item['type'] === 'place' ? 'spot:'.$item['id'] : 'event:'.$item['id'].(isset($item['occurrence_start']) ? ':'.Carbon::parse($item['occurrence_start'])->getTimestamp() : ''),
                    'title' => (string) $item['title'],
                    'date' => $item['date'] ?? null,
                    'place' => $item['place'] ?? null,
                    'label' => $item['label'] ?? null,
                    'area' => $item['area'] ?? null,
                ], $this->resource['saved']['items'] ?? []),
            ] : null,
            /** @var array{count: int, tomorrow: array{date: string|null, plans: array<int, array{title: string, place: string|null, start: string|null}>}, later: array<int, array{date: string, title: string, start: string|null, place: string|null}>} */
            'coming_up' => [
                'count' => (int) ($comingUp['count'] ?? 0),
                'tomorrow' => [
                    'date' => $comingUp['tomorrow']['date'] ?? null,
                    'plans' => array_map(fn (array $plan) => [
                        'title' => (string) $plan['title'],
                        'place' => $plan['location'] ?? null,
                        'start' => $plan['start'] ?? null,
                    ], $comingUp['tomorrow']['plans'] ?? []),
                ],
                // The days after tomorrow; tomorrow has its own row.
                'later' => array_values(array_map(fn (array $item) => [
                    'date' => (string) $item['date'],
                    'title' => (string) $item['title'],
                    'start' => $item['start_time'] ?? null,
                    'place' => $item['location']['label'] ?? null,
                ], array_filter($comingUp['items'] ?? [], fn (array $item) => ($item['date'] ?? null) !== ($comingUp['tomorrow']['date'] ?? null)))),
            ],
        ];
    }

    /**
     * Now, and the next hours: the chip, the Cologne today card and its forecast row.
     *
     * @param  array<string, mixed>|null  $now
     * @param  array<string, mixed>|null  $forecast
     * @return array<string, mixed>|null
     */
    private function weather(?array $now, ?array $forecast, array $days): ?array
    {
        if (! ($now['available'] ?? false)) {
            return null;
        }
        $slot = fn (array $hour) => [
            'hour' => (string) $hour['hour'],
            'temperature' => isset($hour['temperature']) ? (int) round((float) $hour['temperature']) : null,
            'icon' => $hour['icon'] ?? null,
            'rain' => isset($hour['precip_probability']) ? (int) $hour['precip_probability'] : null,
        ];
        $today = ($days[0]['available'] ?? false) ? $days[0]['hours'] : [];
        $temps = array_values(array_filter(array_map(fn (array $hour) => $hour['temperature'] ?? null, $today), fn ($value) => $value !== null));
        // The next twelve hours from this one, running into tomorrow when the evening is short.
        $from = CarbonImmutable::now('Europe/Berlin')->startOfHour();
        $ahead = collect(array_slice($days, 0, 2))
            ->flatMap(fn (array $day) => ($day['available'] ?? false) ? $day['hours'] : [])
            ->filter(fn (array $hour) => isset($hour['time']) && CarbonImmutable::parse($hour['time'])->greaterThanOrEqualTo($from))
            ->take(12)->values();

        return [
            'temperature' => (int) round((float) $now['temperature']),
            'condition' => (string) $now['condition'],
            'icon' => (string) $now['icon'],
            'summary' => $forecast['rain_summary'] ?? null,
            'cycling' => $forecast['bike_score'] ?? null,
            'feels_like' => isset($now['feels_like']) ? (int) $now['feels_like'] : null,
            'high' => $temps === [] ? null : (int) round(max($temps)),
            'low' => $temps === [] ? null : (int) round(min($temps)),
            'hours' => $ahead->map($slot)->all(),
            // Today hour by hour, for each plan's hour (For your day).
            'today' => array_map(fn (array $hour) => [...$slot($hour), 'condition' => $hour['condition'] ?? null], $today),
            // The days ahead: their low and high, the afternoon's sky, the most rain likely.
            'days' => array_values(array_filter(array_map(function (array $day) {
                if (! ($day['available'] ?? false) || $day['hours'] === []) {
                    return null;
                }
                $temps = array_values(array_filter(array_map(fn (array $hour) => $hour['temperature'] ?? null, $day['hours']), fn ($value) => $value !== null));
                $afternoon = collect($day['hours'])->first(fn (array $hour) => $hour['hour'] === '14:00') ?? $day['hours'][intdiv(count($day['hours']), 2)];
                $rain = array_values(array_filter(array_map(fn (array $hour) => $hour['precip_probability'] ?? null, $day['hours']), fn ($value) => $value !== null));

                return [
                    'date' => (string) $day['date'],
                    'low' => $temps === [] ? null : (int) round(min($temps)),
                    'high' => $temps === [] ? null : (int) round(max($temps)),
                    'icon' => $afternoon['icon'] ?? null,
                    'rain' => $rain === [] ? null : (int) max($rain),
                ];
            }, $days))),
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
        $legs = collect($trip['journey']['legs'] ?? [])->filter(fn ($leg) => is_array($leg))->values();
        $ride = $legs->first(fn (array $leg) => ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true));
        $line = is_array($ride) ? ($ride['line'] ?? null) : null;
        $first = $legs->first();

        return [
            // The plan it's for: the card goes under that one.
            'plan_id' => isset($trip['target']['id']) ? (string) $trip['target']['id'] : null,
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
            // "6 min walk to the platform · no change".
            'walk_min' => is_array($ride) && is_array($first) && ($first['mode'] ?? null) === 'walk' ? (int) $first['duration_min'] : null,
            'transfers' => isset($trip['journey']['transfers']) ? (int) $trip['journey']['transfers'] : null,
            'live' => ($trip['time_basis'] ?? null) === 'live',
            // The way the card shows, then Other ways, in the order the trip routes take (way 0, 1…).
            'ways' => is_array($trip['journey'] ?? null) ? array_map(
                fn (array $way) => $this->way($way),
                [['spare_min' => $trip['spare_min'] ?? 0, 'status' => $trip['status'] ?? 'fits', 'in_plan' => ($trip['source'] ?? null) === 'planned', 'time_basis' => $trip['time_basis'] ?? null, 'journey' => $trip['journey']],
                    ...array_values(array_filter($trip['alternatives'] ?? [], fn ($way) => is_array($way['journey'] ?? null)))],
            ) : [],
        ];
    }

    /**
     * One way for Other ways (AppJourney::way), with its fit to the plan.
     *
     * @param  array<string, mixed>  $way
     * @return array<string, mixed>
     */
    private function way(array $way): array
    {
        return AppJourney::way($way['journey'], (int) ($way['spare_min'] ?? 0), (string) ($way['status'] ?? 'fits'), (bool) ($way['in_plan'] ?? false), ($way['time_basis'] ?? null) === 'live');
    }

    /**
     * The trip they're on, for the live Leave card: each leg's line, from, to and times.
     *
     * @param  array<string, mixed>|null  $trip
     * @return array<string, mixed>|null
     */
    private function trip(?array $trip): ?array
    {
        if ($trip === null || ! is_array($trip['journey']['legs'] ?? null)) {
            return null;
        }

        return [
            'to' => (string) ($trip['destination']['name'] ?? ''),
            'arrive_time' => $trip['journey']['arrive_time'] ?? null,
            'start_time' => isset($trip['target']['start_at']) ? Carbon::parse($trip['target']['start_at'])->setTimezone('Europe/Berlin')->format('H:i') : null,
            'legs' => $this->legs($trip['journey']),
        ];
    }

    /**
     * Paperwork's most urgent date ("Visa expires tomorrow"), with the documents it needs.
     *
     * @param  array<string, mixed>|null  $strip
     * @return array<string, mixed>|null
     */
    private function deadline(?array $strip): ?array
    {
        return $strip === null ? null : [
            'kind' => (string) $strip['kind'],
            'document' => $strip['document'] ?? null,
            'title' => (string) $strip['title'],
            'date' => (string) $strip['date'],
            'days_remaining' => (int) $strip['days_remaining'],
            'next' => $strip['next'] ?? null,
            'ready' => $strip['documents']['ready'] ?? null,
            'total' => $strip['documents']['total'] ?? null,
            'process' => $strip['link']['process'] ?? null,
        ];
    }

    /**
     * An event for the time they have, as its poster reads: when, where, what it costs.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function idea(array $event): array
    {
        $at = isset($event['occurrence_start']) ? Carbon::parse($event['occurrence_start'])->getTimestamp() : null;

        return [
            'id' => (int) $event['id'],
            // What Add to a plan sends (CandidateRepository's ids).
            'candidate' => $at === null ? "event:{$event['id']}" : "event:{$event['id']}:{$at}",
            'saved' => $at !== null && in_array("{$event['id']}:{$at}", $this->resource['saved_events'] ?? [], true),
            // Why their order put it here, and which of their leanings it matches (Why am I seeing this?).
            'why' => $event['why'] ?? null,
            'reasons' => array_values($event['reasons'] ?? []),
            'title' => (string) $event['title'],
            'category' => (string) ($event['category_label'] ?? ''),
            'emoji' => $event['emoji'] ?? null,
            'starts_at' => $event['occurrence_start'] ?? null,
            'ends_at' => $event['occurrence_end'] ?? null,
            // An untimed listing reads by its series ("Daily until Thu 5 Nov") or "All day".
            'timed' => ($event['time_listed'] ?? true) !== false,
            'untimed' => $event['recurrence_text'] ?? null,
            'tomorrow' => ($event['day'] ?? null) === 'tomorrow',
            'price' => $event['price_text'] ?? null,
            'place' => $event['venue']['name'] ?? null,
            'area' => $event['venue']['veedel'] ?? null,
            // en, de, mixed or none_needed, only when the listing's own text says so (EventEvidence).
            'language' => $event['language'] ?? null,
        ];
    }

    /**
     * The free time before the next plan, or around the person's Veedel: places open then.
     *
     * @param  array<string, mixed>|null  $around
     * @return array<string, mixed>|null
     */
    private function free(?array $around): ?array
    {
        if ($around === null || ($around['places'] ?? []) === []) {
            return null;
        }

        return [
            'anchor' => is_array($around['anchor'] ?? null) ? [
                'title' => (string) $around['anchor']['title'],
                'area' => (string) $around['anchor']['area'],
                'start' => $around['anchor']['start'] ?? null,
                'end' => $around['anchor']['end'] ?? null,
            ] : null,
            'near' => $around['near']['name'] ?? null,
            'at' => (string) $around['at'],
            'places' => array_map(fn (array $place) => [
                'id' => (int) $place['id'],
                'candidate' => 'spot:'.$place['id'],
                'name' => (string) $place['name'],
                'kind' => (string) ($place['kind'] ?? ''),
                'label' => (string) ($place['label'] ?? ''),
                'km' => isset($place['km']) ? (float) $place['km'] : null,
                'open_until' => ($place['hours']['state'] ?? null) === 'open' ? ($place['hours']['until'] ?? null) : null,
                // For See them on the map.
                'lat' => isset($place['lat']) ? (float) $place['lat'] : null,
                'lng' => isset($place['lng']) ? (float) $place['lng'] : null,
            ], array_slice($around['places'], 0, 3)),
        ];
    }

    /**
     * @param  array<string, mixed>  $journey
     * @return array<int, array<string, mixed>>
     */
    private function legs(array $journey): array
    {
        return AppJourney::legs($journey);
    }

    /**
     * The card's state, from the record itself: "Get started" only for someone with no record.
     *
     * @param  array<string, mixed>|null  $paperwork
     */
    private function paperworkStatus(Request $request, ?array $paperwork): string
    {
        if (is_array($paperwork['focus'] ?? null)) {
            return 'next';
        }
        if ($paperwork !== null) {
            return 'clear';
        }
        $user = $request->user();
        $person = $user === null ? null : BureaucracyPerson::query()->where('account_user_id', $user->id)
            ->where('record_status', 'active')->whereHas('dossier', fn ($query) => $query->where('status', 'active'))->first();
        if ($person === null) {
            return 'none';
        }
        if ($person->processing_stopped_at !== null) {
            return 'paused';
        }

        return $user->email_verified_at === null ? 'unconfirmed' : 'clear';
    }
}
