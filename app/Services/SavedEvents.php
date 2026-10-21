<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventBookmark;
use App\Models\User;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SavedEvents
{
    public function __construct(
        private readonly RecurrenceExpander $expander,
        private readonly EventOccurrencePresenter $presenter,
    ) {}

    public function forUser(User $user): array
    {
        return EventBookmark::query()->where('user_id', $user->id)
            ->with(['event' => fn ($query) => $query->visible()->with([
                'mediaAttachments.mediaAsset', 'venue.mediaAttachments.mediaAsset',
                'venue.place.mediaAttachments.mediaAsset',
            ])])->orderBy('occurrence_start')->get()
            ->map(function (EventBookmark $bookmark): array {
                $start = $bookmark->occurrence_start->setTimezone('Europe/Berlin');
                $event = $bookmark->event;
                $exists = $event !== null && collect($this->expander->expand($event, $start->startOfDay(), $start->endOfDay()))
                    ->contains(fn (CarbonImmutable $occurrence) => $occurrence->equalTo($start));
                $end = $exists && $event->ends_at
                    ? $start->addSeconds(CarbonImmutable::parse($event->starts_at)->diffInSeconds($event->ends_at)) : null;

                return [
                    'event_id' => $bookmark->event_id,
                    'occurrence_start' => $start->toIso8601String(),
                    'title' => $bookmark->title,
                    'available' => $exists && $start->isFuture(),
                    'event' => $exists ? $this->presenter->present($event, $start, $end) + ['distance_km' => null, 'travel_min' => null] : null,
                ];
            })->all();
    }

    public function set(User $user, int $eventId, CarbonImmutable $start, bool $saved): void
    {
        $identity = ['user_id' => $user->id, 'event_id' => $eventId, 'occurrence_start' => $start->toIso8601String()];
        if (! $saved) {
            EventBookmark::query()->where($identity)->delete();

            return;
        }

        $occurrence = Event::occurringBetween($start->startOfDay(), $start->endOfDay(), [$eventId])
            ->first(fn ($item) => $item['starts_at']->equalTo($start));
        if (! $occurrence || ! $start->isFuture()) {
            throw ValidationException::withMessages(['occurrence_start' => 'This event occurrence is no longer available to save.']);
        }

        EventBookmark::query()->updateOrCreate($identity, ['title' => $occurrence['event']->title_en ?: $occurrence['event']->title]);
    }
}
