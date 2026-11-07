<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EventsDayRequest;
use App\Http\Requests\Events\SaveEventRequest;
use App\Http\Resources\Api\V1\EventsDayResource;
use App\Models\Event;
use App\Models\EventBookmark;
use App\Services\SavedEvents;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class EventsController extends Controller
{
    /** How many days ahead the date pill offers. */
    private const DAYS = 7;

    /**
     * What's on for a day (All events on Today): the web panel's listings (App\Home\WhatsOn).
     * Today drops what has already ended and says how many.
     */
    public function day(EventsDayRequest $request, EventOccurrencePresenter $presenter): EventsDayResource
    {
        $now = CarbonImmutable::now('Europe/Berlin');
        $day = CarbonImmutable::parse($request->validated('date') ?? $now->toDateString(), 'Europe/Berlin');
        if ($day->lessThan($now->startOfDay()) || $day->greaterThan($now->addDays(self::DAYS - 1)->endOfDay())) {
            $day = $now->startOfDay();
        }
        $listings = Event::occurringBetween($day->startOfDay(), $day->endOfDay())
            ->map(fn (array $o) => $presenter->present($o['event'], $o['starts_at'], $o['ends_at']))
            ->sortBy('occurrence_start')
            ->values();
        $over = fn (array $event) => $day->isSameDay($now) && isset($event['occurrence_end']) && CarbonImmutable::parse($event['occurrence_end'])->lessThanOrEqualTo($now);

        return new EventsDayResource([
            'date' => $day->toDateString(),
            'today' => $now->toDateString(),
            'ended' => $listings->filter($over)->count(),
            'days' => $this->days($now),
            'listings' => $listings->reject($over)->values()->all(),
            'saved_events' => EventBookmark::query()->where('user_id', $request->user()->id)->get(['event_id', 'occurrence_start'])
                ->map(fn (EventBookmark $bookmark) => $bookmark->event_id.':'.$bookmark->occurrence_start->getTimestamp())->all(),
        ]);
    }

    /**
     * The days the date pill offers, each with how many listings it has. The same for everyone,
     * read once every five minutes.
     *
     * @return list<array{date: string, count: int}>
     */
    private function days(CarbonImmutable $now): array
    {
        return Cache::remember('events:day-counts:'.$now->toDateString(), now()->addMinutes(5), function () use ($now): array {
            $counts = Event::occurringBetween($now->startOfDay(), $now->addDays(self::DAYS - 1)->endOfDay())
                ->countBy(fn (array $o) => $o['starts_at']->setTimezone('Europe/Berlin')->toDateString());

            return array_map(fn (int $ahead) => [
                'date' => $date = $now->addDays($ahead)->toDateString(),
                'count' => (int) ($counts[$date] ?? 0),
            ], range(0, self::DAYS - 1));
        });
    }

    /** Save an event for later, or let it go: the web's bookmark (EventBookmarkController::store). */
    public function save(SaveEventRequest $request, SavedEvents $events): JsonResponse
    {
        $input = $request->validated();
        $events->remember($request->user(), (int) $input['event_id'], CarbonImmutable::parse($input['occurrence_start'])->setTimezone('Europe/Berlin'), (bool) $input['saved']);

        return response()->json(['saved' => (bool) $input['saved']]);
    }
}
