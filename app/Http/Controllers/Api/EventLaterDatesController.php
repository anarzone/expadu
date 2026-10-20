<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A repeating event's next dates after one of its occurrences, for its
 * details (call #19: only rule-based events have later dates; a one-off
 * listing has none). Up to three, within twelve weeks.
 */
class EventLaterDatesController extends Controller
{
    public function __invoke(Request $request, Event $event): JsonResponse
    {
        abort_unless(Event::query()->visible()->whereKey($event->id)->exists(), 404);
        $input = $request->validate(['after' => ['required', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01']]);

        if ($event->recurrence === null) {
            return response()->json(['dates' => []]);
        }

        $after = CarbonImmutable::parse($input['after'])->setTimezone('Europe/Berlin');
        $dates = Event::occurringBetween($after, $after->addWeeks(12)->endOfDay(), [$event->id])
            ->filter(fn (array $o) => $o['starts_at']->greaterThan($after))
            ->sortBy(fn (array $o) => $o['starts_at'])
            ->take(3)
            ->map(fn (array $o) => [
                'start' => $o['starts_at']->toIso8601String(),
                'end' => $o['ends_at']?->toIso8601String(),
            ])
            ->values();

        return response()->json(['dates' => $dates]);
    }
}
