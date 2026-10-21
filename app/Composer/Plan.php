<?php

namespace App\Composer;

/**
 * An ordered day plan. Immutable — swapping returns a new Plan.
 */
final readonly class Plan
{
    /**
     * @param  list<PlanSlot>  $slots
     * @param  bool  $relaxed  the archetype couldn't be honoured against the
     *                         filtered pool, so the day was filled permissively
     *                         (drives the honest "I widened your picks" notice)
     */
    public function __construct(
        public Constraints $constraints,
        public array $slots,
        public bool $relaxed = false,
        public bool $scheduleFeasible = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'constraints' => $this->constraints->toArray(),
            'slots' => $this->slotArrays(),
            'schedule_feasible' => $this->scheduleFeasible,
        ];
    }

    /**
     * Honest appointment notices derived from serialized slots (Composer and Today).
     *
     * @param  list<array<string, mixed>>  $slots
     * @return list<array{code: string, type: string, text: string}>
     */
    public static function appointmentNotices(array $slots): array
    {
        $notices = [];
        foreach ($slots as $slot) {
            if (! ($slot['is_appointment'] ?? false)) {
                continue;
            }
            if (($slot['routable'] ?? true) === false) {
                $notices[] = ['code' => 'appointment_location_unroutable', 'type' => 'warn', 'appointment_id' => $slot['id'],
                    'text' => 'Travel can\'t plan a journey to your '.$slot['start_time'].' appointment without an address. Add its address to plan travel around it.'];
            }
            if (($slot['duration_known'] ?? true) === false) {
                $notices[] = ['code' => 'appointment_end_unknown', 'type' => 'info', 'appointment_id' => $slot['id'],
                    'text' => 'The end time of your '.$slot['start_time'].' appointment is not known, so the next stop may overlap it.'];
            }
        }

        return $notices;
    }

    /**
     * A leg into or out of an unroutable appointment has no journey (travel unknown,
     * no leave-by). The slot after an appointment of unknown length may overlap it;
     * that is flagged, never treated as a hard conflict.
     *
     * @return list<array<string, mixed>>
     */
    private function slotArrays(): array
    {
        $rows = [];
        $previous = null;
        foreach ($this->slots as $slot) {
            $row = $slot->toArray();
            if ($previous !== null && (! $slot->candidate->routable || ! $previous->candidate->routable)) {
                $row = [...$row, 'travel_known' => false, 'travel_min_from_previous' => null, 'leave_by' => null];
            }
            if ($previous !== null && $previous->candidate->isAppointment() && ! $previous->candidate->durationKnown) {
                $row['may_overlap_previous'] = true;
            }
            $rows[] = $row;
            $previous = $slot;
        }

        return $rows;
    }
}
