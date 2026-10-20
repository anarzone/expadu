<?php

namespace App\Day;

use App\Composer\TravelEstimator;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\Journey;
use App\Transit\Dto\Leg;
use Carbon\CarbonImmutable;

/**
 * When to leave for the next thing, and whether the arrival leaves enough room.
 *
 * Official appointments want ten minutes in hand (queues, security checks),
 * everything else five. The trip to take is the latest one that still leaves
 * that margin; when the later one is too tight, the earlier one is taken and
 * the later one is named, so the person sees why (owner decision, 9 Oct 2026).
 *
 * Status: fits (margin met), tight (arrives before the start but inside the
 * margin), late (nothing arrives in time; the earliest arrival is given) or
 * unavailable (no starting point, no recorded place, or no route). Nothing is
 * estimated: every time comes from a planned journey, labelled live or
 * scheduled from its legs.
 */
class NextTrip
{
    public const OFFICIAL_MARGIN_MIN = 10;

    public const PLAN_MARGIN_MIN = 5;

    /** Closer than this to the place, there's no trip to make. */
    private const ALREADY_THERE_METRES = 75.0;

    /** A departure in the last minute still counts as catchable ("Leave now"). */
    private const GRACE_SECONDS = 60;

    /** Routing for the next trip never holds the request longer than this. */
    private const BUDGET_SECONDS = 8.0;

    private const MAX_ALTERNATIVES = 4;

    public function __construct(
        private readonly RouteService $routes,
        private readonly TravelEstimator $distance,
    ) {}

    public static function marginFor(DayItem $item): int
    {
        return $item->official ? self::OFFICIAL_MARGIN_MIN : self::PLAN_MARGIN_MIN;
    }

    /**
     * @return array<string, mixed>
     */
    public function for(DayItem $target, ?TripOrigin $origin, CarbonImmutable $now): array
    {
        $margin = self::marginFor($target);
        $base = [
            'target' => [
                'id' => $target->id,
                'title' => $target->title,
                'kind' => $target->kind,
                'official' => $target->official,
                'start_at' => $target->startAt?->setTimezone('Europe/Berlin')->toIso8601String(),
                'start_time' => $target->startAt?->setTimezone('Europe/Berlin')->format('H:i'),
                'location' => $target->locationLabel,
            ],
            'from' => $origin?->toArray(),
            'margin_min' => $margin,
            'checked_at' => $now->setTimezone('Europe/Berlin')->toIso8601String(),
            // Stop names carry the city ("Köln Neumarkt"); the card shows
            // them as the stop signs do.
            'city' => config('geocoding.search_default.city'),
        ];

        if ($target->startAt === null || ! $target->startAt->greaterThan($now)) {
            return $this->unavailable($base, 'not_upcoming');
        }
        $to = $target->point();
        if ($to === null) {
            return $this->unavailable($base, 'destination_unknown');
        }
        if ($origin === null) {
            return $this->unavailable($base, 'location_unknown');
        }

        $notBefore = $origin->notBefore !== null && $origin->notBefore->greaterThan($now) ? $origin->notBefore : $now;
        if (! $target->startAt->greaterThan($notBefore)) {
            return $this->unavailable($base, 'no_time_between');
        }

        if (1000 * $this->distance->haversineKm($origin->point->lat, $origin->point->lng, $to->lat, $to->lng) <= self::ALREADY_THERE_METRES) {
            return [...$base, ...$this->empty(), 'status' => 'fits', 'reason' => 'already_there',
                'spare_min' => $this->minutesBetween($notBefore, $target->startAt)];
        }

        $routes = $this->routes->withTimeBudget(self::BUDGET_SECONDS);
        $result = $routes->plan($origin->point, $to, $target->startAt, 8, true);
        if ($result->source === 'degraded') {
            return [...$this->unavailable($base, 'routing_unavailable'), 'degraded' => $result->degraded];
        }

        $options = array_map(fn (Journey $journey) => $this->option($journey, $target->startAt, $notBefore, $margin), $result->journeys);
        $catchable = array_values(array_filter($options, fn (array $o) => $o['catchable']));

        $fitting = array_values(array_filter($catchable, fn (array $o) => $o['spare_min'] >= $margin));
        $tight = array_values(array_filter($catchable, fn (array $o) => $o['spare_min'] >= 0 && $o['spare_min'] < $margin));

        if ($fitting !== []) {
            // The latest leave that keeps the margin; the earliest arrival breaks a tie.
            usort($fitting, fn (array $a, array $b) => [$b['leave'], $a['arrive']] <=> [$a['leave'], $b['arrive']]);
            $chosen = $fitting[0];
            $later = array_values(array_filter($tight, fn (array $o) => $o['leave'] > $chosen['leave']));
            usort($later, fn (array $a, array $b) => $b['leave'] <=> $a['leave']);
            $skipped = $later[0] ?? null;

            return $this->answer($base, $options, $chosen, 'fits', $skipped ? 'earlier_for_margin' : 'margin_met', $now, $result->source, $skipped);
        }

        if ($tight !== []) {
            // Nothing keeps the margin: the most room before the start.
            usort($tight, fn (array $a, array $b) => [$b['spare_min'], $b['leave']] <=> [$a['spare_min'], $a['leave']]);

            return $this->answer($base, $options, $tight[0], 'tight', 'only_tight', $now, $result->source);
        }

        // Nothing arrives in time from here: the earliest arrival, and by how much it's late.
        $soonest = $routes->plan($origin->point, $to, $notBefore, 6, false);
        $late = array_values(array_filter(
            array_map(fn (Journey $journey) => $this->option($journey, $target->startAt, $notBefore, $margin), $soonest->journeys),
            fn (array $o) => $o['catchable'],
        ));
        if ($soonest->source === 'degraded' || $late === []) {
            return $this->unavailable($base, $options === [] ? 'no_route' : 'no_connection_in_time');
        }
        usort($late, fn (array $a, array $b) => [$a['arrive'], $b['leave']] <=> [$b['arrive'], $a['leave']]);

        return $this->answer($base, [...$options, ...$late], $late[0], 'late', 'no_connection_in_time', $now, $soonest->source);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  list<array<string, mixed>>  $options
     * @param  array<string, mixed>  $chosen
     * @param  array<string, mixed>|null  $skipped
     * @return array<string, mixed>
     */
    private function answer(array $base, array $options, array $chosen, string $status, string $reason, CarbonImmutable $now, string $source, ?array $skipped = null): array
    {
        $journey = $chosen['journey'];
        $tz = 'Europe/Berlin';
        $alternatives = [];
        foreach ($options as $o) {
            if ($o['key'] !== $chosen['key'] && ($o['catchable'] || $o['cancelled'])) {
                $alternatives[$o['key']] ??= $o;
            }
        }
        $alternatives = array_values($alternatives);
        usort($alternatives, fn (array $a, array $b) => $a['leave'] <=> $b['leave']);

        return [
            ...$base,
            'status' => $status,
            'reason' => $reason,
            'leave_at' => $journey->departAt->setTimezone($tz)->toIso8601String(),
            'leave_time' => $journey->departAt->setTimezone($tz)->format('H:i'),
            'leave_in_min' => max(0, $this->minutesBetween($now, $journey->departAt)),
            'arrive_at' => $journey->arriveAt->setTimezone($tz)->toIso8601String(),
            'arrive_time' => $journey->arriveAt->setTimezone($tz)->format('H:i'),
            'spare_min' => $chosen['spare_min'],
            'late_by_min' => $status === 'late' ? -$chosen['spare_min'] : null,
            'journey' => $journey->toArray(),
            'time_basis' => $chosen['realtime'] ? 'live' : 'scheduled',
            'source' => $source,
            'skipped' => $skipped === null ? null : $this->summary($skipped, $base['margin_min']),
            'alternatives' => array_map(
                fn (array $o) => $this->summary($o, $base['margin_min']) + ['journey' => $o['journey']->toArray()],
                array_slice($alternatives, 0, self::MAX_ALTERNATIVES),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function option(Journey $journey, CarbonImmutable $start, CarbonImmutable $notBefore, int $margin): array
    {
        $cancelled = array_filter($journey->legs, fn (Leg $leg) => $leg->cancelled) !== [];

        return [
            'key' => $journey->departAt->getTimestamp().'|'.$journey->arriveAt->getTimestamp().'|'.implode(',', $journey->lines()),
            'journey' => $journey,
            'leave' => $journey->departAt->getTimestamp(),
            'arrive' => $journey->arriveAt->getTimestamp(),
            'spare_min' => $this->minutesBetween($journey->arriveAt, $start),
            'cancelled' => $cancelled,
            'catchable' => ! $cancelled && $journey->departAt->getTimestamp() >= $notBefore->getTimestamp() - self::GRACE_SECONDS,
            'realtime' => array_filter($journey->legs, fn (Leg $leg) => $leg->isTransit() && $leg->realtime) !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function summary(array $option, int $margin): array
    {
        $tz = 'Europe/Berlin';
        $journey = $option['journey'];
        $ride = collect($journey->legs)->first(fn (Leg $leg) => $leg->isTransit());

        return [
            'leave_at' => $journey->departAt->setTimezone($tz)->toIso8601String(),
            'leave_time' => $journey->departAt->setTimezone($tz)->format('H:i'),
            'arrive_at' => $journey->arriveAt->setTimezone($tz)->toIso8601String(),
            'arrive_time' => $journey->arriveAt->setTimezone($tz)->format('H:i'),
            'spare_min' => $option['spare_min'],
            'status' => match (true) {
                $option['cancelled'] => 'cancelled',
                $option['spare_min'] >= $margin => 'fits',
                $option['spare_min'] >= 0 => 'tight',
                default => 'late',
            },
            'mode' => $journey->mode(),
            'lines' => $journey->lines(),
            // The tram the person knows it by ("Your 13:24"), not when they leave the door.
            'ride' => $ride === null ? null : [
                'line' => $ride->lineName,
                'mode' => $ride->mode,
                'time' => $ride->departAt->setTimezone($tz)->format('H:i'),
                'from' => $ride->from->name,
            ],
            'time_basis' => $option['realtime'] ? 'live' : 'scheduled',
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function unavailable(array $base, string $reason): array
    {
        return [...$base, ...$this->empty(), 'status' => 'unavailable', 'reason' => $reason];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'leave_at' => null, 'leave_time' => null, 'leave_in_min' => null,
            'arrive_at' => null, 'arrive_time' => null, 'spare_min' => null, 'late_by_min' => null,
            'journey' => null, 'time_basis' => null, 'source' => null, 'skipped' => null, 'alternatives' => [],
        ];
    }

    /** Whole minutes from $from to $to, rounded down (negative when $to is earlier). */
    private function minutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }
}
