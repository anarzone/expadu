<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventReminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * "🔔 Remind me" on an event occurrence: set (or re-timed) or removed; the scheduled
 * dispatcher delivers through the in-app/push notification channel. The event sheet
 * and Composer's remind_me both set it here.
 */
final class EventReminders
{
    public const OFFSETS = ['1h', '3h', 'morning'];

    /** @return array{id: int, remind_at: string} */
    public function set(User $user, int $eventId, CarbonImmutable $start, string $offset): array
    {
        $start = $start->setTimezone('Europe/Berlin');
        $occurrence = Event::occurringBetween($start->startOfDay(), $start->endOfDay(), [$eventId])
            ->first(fn ($item) => $item['starts_at']->equalTo($start));
        if (! $occurrence || ! $start->isFuture()) {
            throw ValidationException::withMessages(['occurrence_start' => 'This event occurrence is no longer available for a reminder.']);
        }
        [$offsetMinutes, $remindAt] = match ($offset) {
            '1h' => [60, $start->subHour()],
            '3h' => [180, $start->subHours(3)],
            // "Morning of" an event that starts before 08:00 would land
            // AFTER the start and never fire — fall back to 1h before.
            'morning' => $start->hour < 8 ? [60, $start->subHour()] : [0, $start->setTime(8, 0)],
        };
        $reminder = EventReminder::query()->updateOrCreate(
            ['user_id' => $user->id, 'event_id' => $eventId, 'occurrence_start' => $start],
            ['offset_minutes' => $offsetMinutes, 'remind_at' => $remindAt, 'sent_at' => null],
        );

        return ['id' => $reminder->id, 'remind_at' => $remindAt->toIso8601String()];
    }

    public function forget(User $user, int $eventId, CarbonImmutable $start): void
    {
        EventReminder::query()->where('user_id', $user->id)->where('event_id', $eventId)->where('occurrence_start', $start)->delete();
    }

    /** The pending reminder's offset ("1h", "3h", "morning"), or null without one. */
    public function current(User $user, int $eventId, CarbonImmutable $start): ?string
    {
        $minutes = EventReminder::query()->where('user_id', $user->id)->where('event_id', $eventId)
            ->where('occurrence_start', $start)->whereNull('sent_at')->value('offset_minutes');

        return match (true) {
            $minutes === null => null,
            (int) $minutes === 180 => '3h',
            (int) $minutes === 0 => 'morning',
            default => '1h',
        };
    }
}
