<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventReminder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "🔔 Remind me" on an event occurrence. POST sets (or re-times) the
 * reminder, DELETE removes it; the scheduled dispatcher delivers
 * through the in-app/push notification channel.
 */
class EventReminderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'occurrence_start' => ['required', 'date'],
            'offset' => ['required', 'in:1h,3h,morning'],
        ]);

        $occurrenceStart = CarbonImmutable::parse($validated['occurrence_start']);

        [$offsetMinutes, $remindAt] = match ($validated['offset']) {
            '1h' => [60, $occurrenceStart->subHour()],
            '3h' => [180, $occurrenceStart->subHours(3)],
            // "Morning of" an event that starts before 08:00 would land
            // AFTER the start and never fire — fall back to 1h before.
            'morning' => $occurrenceStart->hour < 8
                ? [60, $occurrenceStart->subHour()]
                : [0, $occurrenceStart->setTime(8, 0)],
        };

        $reminder = EventReminder::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'event_id' => $validated['event_id'],
                'occurrence_start' => $occurrenceStart,
            ],
            [
                'offset_minutes' => $offsetMinutes,
                'remind_at' => $remindAt,
                'sent_at' => null,
            ],
        );

        return response()->json(['id' => $reminder->id, 'remind_at' => $remindAt->toIso8601String()]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $request->validate(['occurrence_start' => ['required', 'date']]);

        EventReminder::query()
            ->where('user_id', $request->user()->id)
            ->where('event_id', $event->id)
            ->where('occurrence_start', CarbonImmutable::parse($request->input('occurrence_start')))
            ->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * The user's pending reminders — lets the events page render the
     * filled-bell confirmed state.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => EventReminder::query()
                ->where('user_id', $request->user()->id)
                ->whereNull('sent_at')
                ->where('occurrence_start', '>=', now())
                ->get(['id', 'event_id', 'occurrence_start', 'offset_minutes'])
                ->map(fn (EventReminder $reminder) => [
                    'id' => $reminder->id,
                    'event_id' => $reminder->event_id,
                    'occurrence_start' => $reminder->occurrence_start->toIso8601String(),
                    'offset_minutes' => $reminder->offset_minutes,
                ]),
        ]);
    }
}
