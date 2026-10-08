<?php

namespace App\Composer;

use App\Bureaucracy\Assessment\AssessmentRevision;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Only the account holder's explicitly recorded appointments constrain a day plan. */
class AppointmentRepository
{
    private const LongestAppointmentMinutes = 1440;

    public function __construct(private AccountHolderPlan $plans, private AssessmentRevision $revisions) {}

    /** @return list<Candidate> */
    public function within(User $user, Constraints $constraints): array
    {
        return $this->snapshot($user, $constraints)['candidates'];
    }

    /** Capture candidates and their freshness binding from the same authorised read. */
    public function snapshot(User $user, Constraints $constraints): array
    {
        $recorded = $this->recorded($user, $constraints);
        $candidates = [];
        foreach ($recorded['appointments'] as $appointment) {
            $start = CarbonImmutable::parse($appointment['starts_at'])->setTimezone($appointment['timezone']);
            $duration = $appointment['duration_minutes'] ?? null;
            if ($start->lessThan($constraints->windowStart)) {
                throw ValidationException::withMessages(['appointments' => $duration === null
                    ? 'A recorded appointment with an unknown length starts before this plan and may still be going on. Adjust the planning time or add its length first.'
                    : 'A recorded appointment overlaps the edge of this plan. Adjust the planning time or review the appointment first.']);
            }
            if ($duration !== null && $start->addMinutes($duration)->greaterThan($constraints->windowEnd)) {
                throw ValidationException::withMessages(['appointments' => 'A recorded appointment overlaps the edge of this plan. Adjust the planning time or review the appointment first.']);
            }
            $location = $appointment['location'] ?? null;
            if (! is_array($location)) {
                throw ValidationException::withMessages(['appointments' => 'A recorded appointment falls within this plan, but its meeting place is unknown. Add its location before planning travel around it.']);
            }
            // A text-only place stays unroutable: no coordinates are invented and no journey is computed.
            $routable = isset($location['lat'], $location['lng']);
            $candidates[] = new Candidate(
                id: 'appointment:'.$appointment['id'], type: 'appointment', name: 'Your recorded appointment',
                lat: $routable ? (float) $location['lat'] : NAN, lng: $routable ? (float) $location['lng'] : NAN, veedel: null, category: 'appointment',
                outdoor: false, typicalDurationMin: $duration ?? 0, costTier: 'unknown',
                opensAt: null, closesAt: null, fixedStart: $start, swappable: false, subtitle: $location['label'] ?? null,
                routable: $routable, durationKnown: $duration !== null,
            );
        }

        return ['candidates' => $candidates, 'revision' => $this->revisions->for($recorded)];
    }

    /** Independent of unrelated facts and catalogue changes: recorded timing survives guidance withdrawal. */
    public function revision(User $user, Constraints $constraints): string
    {
        return $this->revisions->for($this->recorded($user, $constraints));
    }

    private function recorded(User $user, Constraints $constraints): array
    {
        $plan = $this->plans->for($user);
        $appointments = [];
        foreach ($plan['timeline'] ?? [] as $event) {
            if ($event['kind'] !== 'appointment' || $event['state'] !== 'recorded') {
                continue;
            }
            $start = CarbonImmutable::parse($event['starts_at']);
            // An unknown length may run up to the longest recordable appointment.
            if ($start->lessThan($constraints->windowEnd) && $start->addMinutes($event['duration_minutes'] ?? self::LongestAppointmentMinutes)->greaterThan($constraints->windowStart)) {
                $appointments[] = array_intersect_key($event, array_flip(['id', 'starts_at', 'timezone', 'duration_minutes', 'location']));
            }
        }
        usort($appointments, fn ($one, $two) => strcmp($one['id'], $two['id']));

        return ['schema' => 'composer.appointments.1', 'actor_id' => $user->id, 'person_id' => $plan['person_id'] ?? null,
            'window_start' => $constraints->windowStart->toIso8601String(), 'window_end' => $constraints->windowEnd->toIso8601String(),
            'appointments' => $appointments];
    }
}
