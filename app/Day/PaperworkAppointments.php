<?php

namespace App\Day;

use App\Bureaucracy\ReadModel\RecordedAppointmentsBetween;
use App\Day\Contracts\RecordedAppointments;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Paperwork v2: the appointments the account holder recorded, with the
 * place and length they recorded, read without compiling the plan
 * (RecordedAppointmentsBetween: the plan's own gates and folding, so an
 * appointment Paperwork wouldn't show, the day doesn't either). A length
 * not recorded stays unknown (no end), and a text-only place carries no
 * coordinates, so no trip is worked out to it. Never a family member's.
 */
class PaperworkAppointments implements RecordedAppointments
{
    public function __construct(private readonly RecordedAppointmentsBetween $appointments) {}

    public function between(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = [];
        foreach ($this->appointments->for($user, $from, $to) as $row) {
            $start = CarbonImmutable::parse($row['starts_at'])->setTimezone('Europe/Berlin');
            $duration = $row['duration_minutes'];
            $location = is_array($row['location']) ? $row['location'] : null;
            $routable = isset($location['lat'], $location['lng']);

            $items[] = new DayItem(
                id: 'appointment:'.$row['appointment_id'],
                kind: 'appointment',
                source: 'paperwork',
                // The process it's for, in the catalogue's words ("EU Blue Card appointment").
                title: $row['process_title'] !== null ? "{$row['process_title']} appointment" : 'Your recorded appointment',
                startAt: $start,
                endAt: $duration === null ? null : $start->addMinutes((int) $duration),
                official: true,
                locationLabel: $location['label'] ?? null,
                lat: $routable ? (float) $location['lat'] : null,
                lng: $routable ? (float) $location['lng'] : null,
                fixed: true,
                processId: $row['process_id'],
                processKey: $row['occurrence_key'],
            );
        }

        return $items;
    }
}
