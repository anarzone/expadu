<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventReminder;
use App\Services\EventReminders;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "🔔 Remind me" on an event occurrence. POST sets (or re-times) the
 * reminder, DELETE removes it (EventReminders, which Composer's remind_me
 * uses too).
 */
class EventReminderController extends Controller
{
    public function store(Request $request, EventReminders $reminders): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'occurrence_start' => ['required', 'date'],
            'offset' => ['required', 'in:1h,3h,morning'],
        ]);

        return response()->json($reminders->set($request->user(), (int) $validated['event_id'], CarbonImmutable::parse($validated['occurrence_start']), $validated['offset']));
    }

    public function destroy(Request $request, Event $event, EventReminders $reminders): JsonResponse
    {
        $request->validate(['occurrence_start' => ['required', 'date']]);
        $reminders->forget($request->user(), $event->id, CarbonImmutable::parse($request->input('occurrence_start')));

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
