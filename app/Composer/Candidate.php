<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * One plannable thing: a spot (flexible timing), a curated event (fixed
 * start), or the user's own booked appointment (fixed start, immovable).
 * Snapshot of everything the pure pipeline needs so no stage touches the
 * database.
 */
final readonly class Candidate
{
    public function __construct(
        public string $id,         // "spot:12" | "event:34[:occurrence_timestamp]" | "appointment:7"
        public string $type,       // spot | event | appointment
        public string $name,
        public float $lat,
        public float $lng,
        public ?string $veedel,
        public string $category,
        public bool $outdoor,
        public int $typicalDurationMin,
        public string $costTier,           // free | low | normal | unknown
        public ?CarbonImmutable $opensAt,  // null = always open within window
        public ?CarbonImmutable $closesAt,
        public ?CarbonImmutable $fixedStart = null, // events + appointments
        public bool $swappable = true,              // false for appointments
        public ?string $subtitle = null,            // e.g. office + documents line
        public bool $isLandmark = false,            // notable (OSM wikidata/wikipedia) → hero pick
        public bool $closedToday = false,           // real opening hours say shut on the plan's day
        public bool $hoursAssumed = false,          // opens/closes are category defaults, not verified hours
        public ?string $description = null,
        /** @var list<string> */
        public array $tags = [],
        public ?float $qualityScore = null,
        public ?int $travelMinutesFromOrigin = null,
        public ?string $destinationGroupId = null,
        public string $access = 'unknown',
        /** @var list<string> */
        public array $factConflicts = [],
        public int $factRevision = 0,
        /** @var array<string, mixed> */
        public array $placeFacts = [],
        public ?float $distanceKmFromOrigin = null,
    ) {}

    /** Earliest complete visit, retaining split and overnight source intervals. */
    public function nextVisitStart(CarbonImmutable $earliest, CarbonImmutable $latestEnd, ?int $duration = null): ?CarbonImmutable
    {
        $duration ??= $this->typicalDurationMin;
        $hours = $this->placeFacts['hours'] ?? [];
        $week = $hours['parsed'] ?? null;
        if (($hours['status'] ?? null) === 'known' && is_array($week)
            && array_key_exists(strtolower($earliest->format('D')), $week)) {
            for ($day = $earliest->startOfDay()->subDay(); $day->lessThanOrEqualTo($latestEnd); $day = $day->addDay()) {
                foreach ($week[strtolower($day->format('D'))] ?? [] as $interval) {
                    if (! is_array($interval) || count($interval) !== 2) {
                        continue;
                    }
                    [$openHour, $openMinute] = array_pad(array_map('intval', explode(':', $interval[0])), 2, 0);
                    [$closeHour, $closeMinute] = array_pad(array_map('intval', explode(':', $interval[1])), 2, 0);
                    $opens = $day->setTime($openHour, $openMinute);
                    $closes = $day->setTime($closeHour, $closeMinute);
                    if ($closes->lessThanOrEqualTo($opens)) {
                        $closes = $closes->addDay();
                    }
                    $start = $earliest->max($opens);
                    if ($start->addMinutes($duration)->lessThanOrEqualTo($closes->min($latestEnd))) {
                        return $start;
                    }
                }
            }

            return null;
        }
        if ($this->closedToday) {
            return null;
        }
        $start = $this->opensAt === null ? $earliest : $earliest->max($this->opensAt);
        $end = $this->closesAt === null ? $latestEnd : $latestEnd->min($this->closesAt);

        return $start->addMinutes($duration)->lessThanOrEqualTo($end) ? $start : null;
    }

    public function coversVisit(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $end->greaterThan($start)
            && ($this->nextVisitStart($start, $end, (int) $start->diffInMinutes($end))?->equalTo($start) ?? false);
    }

    public function isFixedTime(): bool
    {
        return $this->fixedStart !== null;
    }

    public function isAppointment(): bool
    {
        return $this->type === 'appointment';
    }
}
