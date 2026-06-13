<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

final readonly class PlanSlot
{
    public function __construct(
        public Candidate $candidate,
        public CarbonImmutable $startAt,
        public CarbonImmutable $endAt,
        public int $travelMinFromPrevious,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->candidate->id,
            'type' => $this->candidate->type,
            'name' => $this->candidate->name,
            'subtitle' => $this->candidate->subtitle,
            'category' => $this->candidate->category,
            'veedel' => $this->candidate->veedel,
            'lat' => $this->candidate->lat,
            'lng' => $this->candidate->lng,
            'outdoor' => $this->candidate->outdoor,
            'cost_tier' => $this->candidate->costTier,
            'is_appointment' => $this->candidate->isAppointment(),
            'swappable' => $this->candidate->swappable,
            'start_at' => $this->startAt->toIso8601String(),
            'end_at' => $this->endAt->toIso8601String(),
            'start_time' => $this->startAt->format('H:i'),
            'end_time' => $this->endAt->format('H:i'),
            'travel_min_from_previous' => $this->travelMinFromPrevious,
            // When there's travel into this slot, the latest you can leave
            // the previous stop — drives the anchor's "leave by" line.
            'leave_by' => $this->travelMinFromPrevious > 0
                ? $this->startAt->subMinutes($this->travelMinFromPrevious)->format('H:i')
                : null,
            'closes_at' => $this->candidate->closesAt?->format('H:i'),
        ];
    }
}
