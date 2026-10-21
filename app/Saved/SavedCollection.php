<?php

namespace App\Saved;

use App\Models\EventBookmark;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Services\SavedEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything a person saved for later, places and events in one list,
 * newest first (call #4): Today's "Saved for later", Composer's Saved ideas
 * and the shell's Saved count read this, not the two stores apart. It reads
 * the existing stores (event bookmarks, place feedback) and adds no table.
 * Only the signed-in person's own items; a gone event stays, unavailable,
 * so it says so instead of vanishing.
 */
final class SavedCollection
{
    public function __construct(private SavedEvents $events) {}

    /** @return Collection<int, array<string, mixed>> */
    public function for(User $user): Collection
    {
        $savedAt = EventBookmark::query()->where('user_id', $user->id)
            ->get(['event_id', 'occurrence_start', 'created_at'])
            ->mapWithKeys(fn (EventBookmark $bookmark) => [
                $bookmark->event_id.'|'.$bookmark->occurrence_start->getTimestamp() => $bookmark->created_at?->toIso8601String(),
            ]);

        $events = collect($this->events->forUser($user))->map(function (array $row) use ($savedAt): array {
            $start = CarbonImmutable::parse($row['occurrence_start'])->setTimezone('Europe/Berlin');
            $event = $row['event'];

            return [
                'type' => 'event',
                'key' => "event:{$row['event_id']}:{$start->getTimestamp()}",
                'id' => $row['event_id'],
                'title' => $row['title'],
                'occurrence_start' => $row['occurrence_start'],
                'date' => $start->format('Y-m-d'),
                'start' => ($event['time_listed'] ?? true) ? $start->format('H:i') : null,
                'place' => $event['venue']['name'] ?? null,
                'area' => $event['venue']['veedel'] ?? null,
                'category' => $event['category'] ?? null,
                'available' => $row['available'],
                'saved_at' => $savedAt->get($row['event_id'].'|'.$start->getTimestamp()),
            ];
        });

        $places = SpotFeedback::query()->where('user_id', $user->id)->where('state', 'saved')
            ->with('spot')
            ->get()
            ->map(fn (SpotFeedback $feedback): array => [
                'type' => 'place',
                'key' => "spot:{$feedback->spot_id}",
                'id' => $feedback->spot_id,
                'title' => $feedback->spot?->name ?? 'A saved place',
                'label' => $feedback->spot?->category?->label(),
                'area' => $feedback->spot?->veedel,
                'category' => $feedback->spot?->category?->value,
                'available' => $feedback->spot !== null && $feedback->spot->is_active && $feedback->spot->public_access_allowed,
                'saved_at' => ($feedback->updated_at ?? $feedback->created_at)?->toIso8601String(),
            ]);

        return $events->concat($places)
            ->sortByDesc(fn (array $item) => $item['saved_at'] === null ? 0 : CarbonImmutable::parse($item['saved_at'])->getTimestamp())
            ->values();
    }
}
