<?php

namespace App\Day;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Day\Contracts\RecordedAppointments;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Paperwork v2: the appointments the account holder recorded on their own
 * plan's timeline, with the place and length they recorded. A length not
 * recorded stays unknown (no end), and a text-only place carries no
 * coordinates, so no trip is worked out to it. Only the account holder's
 * own plan is read (AccountHolderPlan), never a family member's.
 */
class PaperworkAppointments implements RecordedAppointments
{
    public function __construct(private readonly AccountHolderPlan $plans) {}

    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $plan = $this->plans->for($user);
        if ($plan === null) {
            return [];
        }
        $processes = array_column([...$plan['processes'] ?? [], ...$plan['history'] ?? []], 'title', 'id');

        $items = [];
        foreach ($plan['timeline'] ?? [] as $event) {
            if (($event['kind'] ?? null) !== 'appointment' || ($event['state'] ?? null) !== 'recorded') {
                continue;
            }
            $start = CarbonImmutable::parse($event['starts_at'])->setTimezone('Europe/Berlin');
            if ($start->lessThan($from) || ! $start->lessThan($to)) {
                continue;
            }
            $duration = $event['duration_minutes'] ?? null;
            $location = is_array($event['location'] ?? null) ? $event['location'] : null;
            $routable = isset($location['lat'], $location['lng']);
            $process = $processes[$event['process_id'] ?? ''] ?? null;

            $items[] = new DayItem(
                id: 'appointment:'.$event['appointment_id'],
                kind: 'appointment',
                source: 'paperwork',
                // The process it's for, in the catalogue's words ("EU Blue Card appointment").
                title: $process !== null ? "{$process} appointment" : 'Your recorded appointment',
                startAt: $start,
                endAt: $duration === null ? null : $start->addMinutes((int) $duration),
                official: true,
                locationLabel: $location['label'] ?? null,
                lat: $routable ? (float) $location['lat'] : null,
                lng: $routable ? (float) $location['lng'] : null,
                fixed: true,
                processId: isset($event['process_id']) ? (int) $event['process_id'] : null,
            );
        }
        usort($items, fn (DayItem $a, DayItem $b) => $a->startAt <=> $b->startAt);

        return $items;
    }
}
