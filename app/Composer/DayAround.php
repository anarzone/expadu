<?php

namespace App\Composer;

use App\Day\Contracts\RecordedAppointments;
use App\Enums\SpotCategory;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What Composer's Your day and its Events and Nearby starters say about the day around
 * the person: tonight's listings (the city's events, the same query Events and the
 * planner use) and, after today's recorded appointment, the nearest pharmacy to it. Only
 * what the listings hold; nothing is shown when they hold nothing.
 */
final class DayAround
{
    /** "Tonight" starts here (Europe/Berlin), or now if later. */
    /** When "tonight" starts, for the day row and Composer's answer alike. */
    public const EVENING = '18:00';

    /** How far "near your appointment" reaches, in metres. */
    private const NEAR = 1000;

    public function __construct(private RecordedAppointments $appointments, private WorkspaceStore $workspace) {}

    /**
     * @return array{tonight: array{count: int, from: string, date: string, titles: list<string>}|null, nearby: array{after: string|null, area: string|null, place: string}|null}
     */
    public function for(User $user, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone('Europe/Berlin');
        $evening = $now->setTimeFromTimeString(self::EVENING);
        $end = $now->startOfDay()->addDay();
        $tonight = Event::occurringBetween(max($now, $evening), $end)
            ->sortBy(fn (array $occurrence) => $occurrence['starts_at'])
            ->values();

        $appointment = $this->appointment($user, $now, $end);
        $pharmacy = $appointment === null ? null : Spot::query()->canonical()
            ->where('category', SpotCategory::Pharmacy)
            ->where('is_active', true)
            ->nearby($appointment['lat'], $appointment['lng'])
            ->first();

        return [
            'tonight' => $tonight->isEmpty() ? null : [
                'count' => $tonight->count(),
                'from' => $tonight->first()['starts_at']->setTimezone('Europe/Berlin')->format('H:i'),
                'date' => $now->toDateString(),
                'titles' => $tonight->take(10)->map(fn (array $occurrence): string => (string) ($occurrence['event']->title_en ?? $occurrence['event']->title))->values()->all(),
            ],
            'nearby' => $pharmacy === null || $pharmacy->distance_meters > self::NEAR ? null : [
                'after' => $appointment['end']?->format('H:i'),
                'area' => $appointment['area'],
                'place' => $pharmacy->name,
            ],
        ];
    }

    /**
     * Today's next appointment with a known place: a recorded one first, otherwise one in
     * today's plan.
     *
     * @return array{lat: float, lng: float, area: ?string, end: ?CarbonImmutable}|null
     */
    private function appointment(User $user, CarbonImmutable $now, CarbonImmutable $end): ?array
    {
        $recorded = collect($this->appointments->between($user, $now->startOfDay(), $end))
            ->first(fn ($item) => $item->routable() && ($item->endAt ?? $item->startAt)?->greaterThan($now));

        if ($recorded !== null) {
            return ['lat' => $recorded->lat, 'lng' => $recorded->lng, 'area' => $recorded->locationLabel,
                'end' => $recorded->endAt?->setTimezone('Europe/Berlin')];
        }

        $slot = collect($this->workspace->stored($user)['plans'][$now->toDateString()]['slots'] ?? [])
            ->first(fn (array $slot): bool => ($slot['type'] ?? null) === 'appointment' && isset($slot['lat'], $slot['lng'])
                && CarbonImmutable::parse($slot['end_at'] ?? $slot['start_at'])->greaterThan($now));

        return $slot === null ? null : ['lat' => (float) $slot['lat'], 'lng' => (float) $slot['lng'],
            'area' => $slot['location'] ?? null, 'end' => isset($slot['end_at']) ? CarbonImmutable::parse($slot['end_at'])->setTimezone('Europe/Berlin') : null];
    }
}
