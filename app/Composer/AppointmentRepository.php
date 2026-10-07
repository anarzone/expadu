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
            if ($start->lessThan($constraints->windowStart) || $start->addMinutes($appointment['duration_minutes'])->greaterThan($constraints->windowEnd)) {
                throw ValidationException::withMessages(['appointments' => 'A recorded appointment overlaps the edge of this plan. Adjust the planning time or review the appointment first.']);
            }
            $location = $appointment['location'] ?? null;
            if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
                throw ValidationException::withMessages(['appointments' => 'A recorded appointment falls within this plan, but its meeting place is unknown. Add its location before planning travel around it.']);
            }
            $candidates[] = new Candidate(
                id: 'appointment:'.$appointment['id'], type: 'appointment', name: 'Your recorded appointment',
                lat: (float) $location['lat'], lng: (float) $location['lng'], veedel: null, category: 'appointment',
                outdoor: false, typicalDurationMin: $appointment['duration_minutes'], costTier: 'unknown',
                opensAt: null, closesAt: null, fixedStart: $start, swappable: false, subtitle: $location['label'] ?? null,
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
            if ($start->lessThan($constraints->windowEnd) && $start->addMinutes($event['duration_minutes'])->greaterThan($constraints->windowStart)) {
                $appointments[] = array_intersect_key($event, array_flip(['id', 'starts_at', 'timezone', 'duration_minutes', 'location']));
            }
        }
        usort($appointments, fn ($one, $two) => strcmp($one['id'], $two['id']));

        return ['schema' => 'composer.appointments.1', 'actor_id' => $user->id, 'person_id' => $plan['person_id'] ?? null,
            'window_start' => $constraints->windowStart->toIso8601String(), 'window_end' => $constraints->windowEnd->toIso8601String(),
            'appointments' => $appointments];
    }
}
