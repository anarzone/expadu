<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Events: the day's listings on the street (the mock's events-next). The
 * page shows one day at a time, the whole day, earlier hours included;
 * filters narrow the wall in the browser, as in the mock.
 */
class EventController extends Controller
{
    public function __construct(private readonly EventOccurrencePresenter $presenter) {}

    public function index(Request $request): Response
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01']]);
        $today = CarbonImmutable::now('Europe/Berlin');
        $day = isset($input['date']) ? CarbonImmutable::parse($input['date'], 'Europe/Berlin') : $today;

        return Inertia::render('events', [
            'date' => $day->toDateString(),
            'today' => $today->toDateString(),
            'listings' => Event::occurringBetween($day->startOfDay(), $day->endOfDay())
                ->map(fn (array $o) => $this->presenter->present($o['event'], $o['starts_at'], $o['ends_at']))
                ->values(),
        ]);
    }

    /**
     * Legacy detail URLs — the v2 page expands inline, no detail route.
     */
    public function show(Request $request, Event $event): RedirectResponse
    {
        abort_unless(Event::query()->visible()->whereKey($event->id)->exists(), 404);
        $input = $request->validate(['start' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01']]);
        $start = isset($input['start']) ? CarbonImmutable::parse($input['start'])->setTimezone('Europe/Berlin') : null;
        if ($start === null) {
            $now = CarbonImmutable::now('Europe/Berlin');
            $start = Event::occurringBetween($now->startOfDay(), $now->addYear(), [$event->id])
                ->first(fn ($item) => $item['starts_at']->isFuture())['starts_at'] ?? CarbonImmutable::parse($event->starts_at);
        }

        return redirect()->route('events', ['event' => $event->id, 'start' => $start->toIso8601String(), 'date' => $start->toDateString()]);
    }

    public function saved(Request $request): RedirectResponse
    {
        return redirect()->route('events', ['saved' => 1]);
    }

    public function join(Request $request, Event $event): RedirectResponse
    {
        $event->attendees()->syncWithoutDetaching([
            $request->user()->id => ['joined_at' => now()],
        ]);

        return back();
    }

    public function leave(Request $request, Event $event): RedirectResponse
    {
        $event->attendees()->detach($request->user()->id);

        return back();
    }
}
