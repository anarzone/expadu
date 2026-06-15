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
        public ?string $why = null,
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
            // Soft timing for the result UI: a time-of-day band + rough
            // duration instead of a rigid clock range (hard times stay on
            // fixed anchors via start_time/leave_by above).
            'is_landmark' => $this->candidate->isLandmark,
            'band' => $this->band(),
            'duration_label' => $this->durationLabel(),
            'why' => $this->why,
        ];
    }

    private function band(): string
    {
        $hour = (int) $this->startAt->format('G');

        return match (true) {
            $hour < 12 => 'Morning',
            $hour < 14 => 'Midday',
            $hour < 18 => 'Afternoon',
            default => 'Evening',
        };
    }

    private function durationLabel(): string
    {
        $halfHours = max(1, (int) round($this->startAt->diffInMinutes($this->endAt) / 30));
        $hours = intdiv($halfHours, 2);
        $half = ($halfHours % 2) === 1 ? '½' : '';

        return '~'.($hours > 0 ? $hours : '').$half.'h';
    }
}
