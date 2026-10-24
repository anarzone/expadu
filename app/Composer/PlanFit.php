<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * Where a place would fit in a day's plan without moving anything (owner, 24 Oct 2026: "Find
 * us two activities" rewrote his Sunday): the first gap it fits, the way there from the stop
 * before and on to the one after, a start on the next 5 minutes, inside its listed hours.
 */
final class PlanFit
{
    /** A day's free time when its plan doesn't say: from 10:00; until 20:00 at least, 23:00 at most. */
    private const FROM = 10;

    private const UNTIL = 20;

    private const LAST = 23;

    public function __construct(private TravelEstimator $travel) {}

    /**
     * @param  array<string, mixed>|null  $plan  the day's plan as kept (WorkspaceStore::plan), or none
     * @return array{start: string, end: string, after: ?string}|null
     */
    public function for(Candidate $place, ?array $plan, CarbonImmutable $day): ?array
    {
        $day = $day->setTimezone('Europe/Berlin')->startOfDay();
        $now = CarbonImmutable::now('Europe/Berlin');
        $window = isset($plan['constraints']) ? Constraints::fromArray($plan['constraints']) : null;
        $earliest = ($window?->windowStart ?? $day->setTime(self::FROM, 0))->max($day->isSameDay($now) ? $now : $day);
        $latest = ($window?->windowEnd ?? $day)->max($day->setTime(self::UNTIL, 0))->min($day->setTime(self::LAST, 0));
        $slots = collect($plan['slots'] ?? [])->sortBy('start_at')->values()->all();

        $before = null;
        foreach ([...$slots, null] as $next) {
            $from = $before === null ? $earliest : CarbonImmutable::parse($before['end_at'])->setTimezone('Europe/Berlin')->max($earliest);
            $until = $next === null ? $latest : CarbonImmutable::parse($next['start_at'])->setTimezone('Europe/Berlin')->min($latest);
            $in = $this->minutes($before, $place);
            $out = $next === null ? 0 : $this->minutes($next, $place);
            $start = $place->nextVisitStart(SlotFiller::fiveUp($from->addMinutes($in)), $until->subMinutes($out));
            if ($start !== null) {
                $start = SlotFiller::fiveUp($start);
                $end = $start->addMinutes($place->typicalDurationMin);
                if ($end->addMinutes($out)->lessThanOrEqualTo($until) && $place->coversVisit($start, $end)) {
                    return ['start' => $start->format('H:i'), 'end' => $end->format('H:i'), 'after' => $before === null ? null : (string) $before['name']];
                }
            }
            $before = $next;
        }

        return null;
    }

    /** Minutes between a stop and the place, or none when the stop has no point (their own plan). */
    private function minutes(?array $slot, Candidate $place): int
    {
        return $slot !== null && is_numeric($slot['lat'] ?? null) && is_numeric($slot['lng'] ?? null)
            ? $this->travel->minutesBetween((float) $slot['lat'], (float) $slot['lng'], $place->lat, $place->lng) : 0;
    }
}
