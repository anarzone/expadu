<?php

namespace App\Home;

use App\Day\Contracts\RecordedAppointments;
use App\Day\DayItem;
use App\Day\DayProjection;
use App\Models\ComposerWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Today's "Coming up" (the mock's .coming-up): the plans on the days after
 * today, from the person's own day plans and recorded Paperwork
 * appointments, read as the day projection reads each day. The first two
 * are shown; the count says how many there are in the next thirty days.
 */
final class ComingUp
{
    private const DAYS = 30;

    private const SHOWN = 2;

    public function __construct(
        private DayProjection $days,
        private RecordedAppointments $appointments,
    ) {}

    /** @return array{count: int, items: list<array<string, mixed>>, tomorrow: array{date: string, plans: list<array{title: string, location: ?string, start: ?string}>}} */
    public function for(User $user, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(DayProjection::TIMEZONE);
        $from = $now->startOfDay()->addDay();
        $until = $from->addDays(self::DAYS);

        $plans = ComposerWorkspace::query()->where('user_id', $user->id)->first()?->state['plans'] ?? [];
        $dates = collect(array_keys($plans))
            ->map(fn (int|string $date) => (string) $date)
            ->filter(fn (string $date) => $date >= $from->format('Y-m-d') && $date < $until->format('Y-m-d'))
            ->merge(collect($this->appointments->between($user, $from, $until))
                ->map(fn (DayItem $item) => $item->startAt?->setTimezone(DayProjection::TIMEZONE)->format('Y-m-d')))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $items = $dates
            ->flatMap(fn (string $date) => array_filter(
                $this->days->itemsOn($user, $date),
                fn (DayItem $item) => ! $item->completed(),
            ))
            ->values();
        $tomorrow = $from->format('Y-m-d');

        return [
            'count' => $items->count(),
            'items' => $items->take(self::SHOWN)
                ->map(fn (DayItem $item) => [
                    ...$item->toArray($now),
                    'date' => $item->startAt?->setTimezone(DayProjection::TIMEZONE)->format('Y-m-d'),
                ])
                ->all(),
            // "Plan tomorrow" tells Composer what tomorrow already holds.
            'tomorrow' => [
                'date' => $tomorrow,
                'plans' => $items
                    ->filter(fn (DayItem $item) => $item->startAt?->setTimezone(DayProjection::TIMEZONE)->format('Y-m-d') === $tomorrow)
                    ->map(fn (DayItem $item) => [
                        'title' => $item->title,
                        'location' => $item->locationLabel,
                        'start' => $item->startAt?->setTimezone(DayProjection::TIMEZONE)->format('H:i'),
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }
}
