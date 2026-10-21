<?php

namespace App\Home;

use App\Day\DayItem;
use App\Day\DayProjection;
use App\Enums\SpotCategory;
use App\Models\Event;
use App\Models\User;
use App\Places\OpenNearby;
use App\Saved\SavedCollection;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Today's discovery (the mock's "A little Cologne" and "Also in ‹area›"),
 * from listings and the person's own saved ideas: tonight's events that are
 * still to come and fit around the person's plans, and the everyday places
 * whose listed hours say they're open near the next plan when it ends.
 * Nothing is sample or guessed; with nothing listed a block is simply absent.
 */
final class TodayDiscovery
{
    /** The kinds the mock looks for near a plan, one place each, in this order. */
    private const KINDS = [
        'bakery' => [SpotCategory::Bakery],
        'cafe' => [SpotCategory::Cafe],
        'pharmacy' => [SpotCategory::Pharmacy],
        // Obst & Gemüse counts as groceries, as on Around here.
        'grocery' => [SpotCategory::Supermarket, SpotCategory::Convenience, SpotCategory::Drugstore, SpotCategory::Greengrocer],
    ];

    public function __construct(
        private DayProjection $days,
        private EventOccurrencePresenter $presenter,
        private OpenNearby $open,
        private SavedCollection $saved,
    ) {}

    /** @return array{part: string, events: list<array<string, mixed>>, around: array<string, mixed>|null} */
    public function for(User $user, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(DayProjection::TIMEZONE);
        $active = collect($this->days->itemsOn($user, $now->format('Y-m-d')))
            ->filter(fn (DayItem $item) => ! $item->completed() && ($item->endAt ?? $item->startAt)?->greaterThanOrEqualTo($now))
            ->sortBy(fn (DayItem $item) => $item->startAt?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        $events = $this->events($now, $active);
        $around = $this->around($user, $now, $active, $events);
        $idea = $around['idea']['id'] ?? null;
        $ideaStart = $around['idea']['occurrence_start'] ?? null;

        // Posters for the two eligible events starting soonest, compact
        // listings for the next three (coordinator, 20 Oct). Posters fill
        // first, and the event "Also in ‹area›" suggests is in neither.
        $listed = $events
            ->reject(fn (array $event) => $event['id'] === $idea && $event['occurrence_start'] === $ideaStart)
            ->take(5)
            ->values()
            ->map(fn (array $event, int $index) => [...$event, 'layout' => $index < 2 ? 'poster' : 'compact']);

        return [
            // A free day's heading (Today decides it's free): "Ideas for
            // this afternoon".
            'part' => $now->hour < 12 ? 'morning' : ($now->hour < 17 ? 'afternoon' : 'evening'),
            'events' => $listed->all(),
            'around' => $around,
        ];
    }

    /**
     * Tonight's events still to come, soonest first: not ended, a fixed start
     * not already passed, and not clashing with a plan that's still ahead.
     *
     * @param  Collection<int, DayItem>  $active
     * @return Collection<int, array<string, mixed>>
     */
    private function events(CarbonImmutable $now, Collection $active): Collection
    {
        return Event::occurringBetween($now->startOfDay(), $now->endOfDay())
            ->filter(function (array $occurrence) use ($now, $active): bool {
                /** @var CarbonImmutable $start */
                $start = $occurrence['starts_at'];
                $end = $occurrence['ends_at'];
                if ($start->lessThan($now) && ($end === null || $end->lessThanOrEqualTo($now))) {
                    return false;
                }
                if ($start->lessThan($now) && $this->presenter->timeListed($start, $end)) {
                    return false;
                }

                return ! $active->contains(fn (DayItem $plan) => $end !== null && $plan->startAt !== null && $plan->endAt !== null
                    && $start->lessThan($plan->endAt) && $end->greaterThan($plan->startAt));
            })
            ->sortBy(fn (array $occurrence) => $occurrence['starts_at']->getTimestamp())
            ->map(fn (array $occurrence) => $this->presenter->present($occurrence['event'], $occurrence['starts_at'], $occurrence['ends_at']))
            ->values();
    }

    /**
     * Around the next plan with a place: one idea in the same area, and the
     * everyday places open there when the plan ends (or now, once it has).
     * The idea is the person's own saved idea first, as the mock's around()
     * has it: a saved place there, or a saved event there after the plan.
     * Then tonight's listings. The point and time stay in this request
     * (OpenNearby).
     *
     * @param  Collection<int, DayItem>  $active
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private function around(User $user, CarbonImmutable $now, Collection $active, Collection $events): ?array
    {
        $anchor = $active->first(fn (DayItem $item) => $item->routable() && $item->locationLabel !== null);
        if (! $anchor instanceof DayItem) {
            return null;
        }

        $at = $anchor->endAt !== null && $anchor->endAt->greaterThan($now) ? $anchor->endAt : $now;
        $area = mb_strtolower(trim((string) $anchor->locationLabel));
        $inArea = fn (?string $value) => mb_strtolower(trim((string) $value)) === $area;
        $fits = fn (array $event) => $inArea($event['venue']['veedel'] ?? null)
            && ($anchor->endAt === null || CarbonImmutable::parse($event['occurrence_start'])->greaterThanOrEqualTo($anchor->endAt));

        // A saved event counts only as one of tonight's eligible listings.
        $listed = $events->keyBy(fn (array $event) => "event:{$event['id']}:".CarbonImmutable::parse($event['occurrence_start'])->getTimestamp());
        $kept = $this->saved->for($user)
            ->filter(fn (array $item) => $item['available'])
            ->map(fn (array $item) => match ($item['type']) {
                'place' => $inArea($item['area']) ? ['place' => $item] : null,
                default => ($event = $listed->get($item['key'])) !== null && $fits($event) ? ['event' => $event] : null,
            })
            ->filter()
            ->first();

        $place = isset($kept['place']) ? [
            'id' => $kept['place']['id'],
            'title' => $kept['place']['title'],
            'area' => $kept['place']['area'],
        ] : null;
        $idea = $kept['event'] ?? ($place === null ? $events->first($fits) : null);

        $places = collect(self::KINDS)
            ->map(fn (array $kinds) => $this->open->around(
                (float) $anchor->lat,
                (float) $anchor->lng,
                $at,
                array_map(fn (SpotCategory $kind) => $kind->value, $kinds),
                limit: 1,
            )->first())
            ->filter()
            ->take(3)
            ->map(fn (array $place) => [...$place, 'label' => SpotCategory::tryFrom($place['kind'])?->label() ?? $place['kind']])
            ->values();

        if ($idea === null && $place === null && $places->isEmpty()) {
            return null;
        }

        return [
            'anchor' => [
                'id' => $anchor->id,
                'title' => $anchor->title,
                'area' => $anchor->locationLabel,
                'start' => $anchor->startAt?->format('H:i'),
                'end' => $anchor->endAt?->format('H:i'),
            ],
            'at' => $at->format('H:i'),
            'idea' => $idea,
            'place' => $place,
            'saved' => $kept !== null,
            'places' => $places->all(),
        ];
    }
}
