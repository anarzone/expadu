<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Events — a time-first feed of curated, composable occurrences. The
 * occurrence list itself comes from GET /api/events; this only seeds
 * the page shell with filters and the Veedel chip options.
 */
class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01']]);

        return Inertia::render('events', [
            'savedOnly' => $request->boolean('saved'),
            'filters' => [
                'date' => $input['date'] ?? null,
                'window' => in_array($request->query('window'), ['today', 'tomorrow', 'weekend', 'week'], true)
                    ? $request->query('window')
                    : 'today',
                'category' => $request->query('category'),
                'veedel' => $request->query('veedel'),
                'free' => $request->boolean('free'),
                'venue' => $request->query('venue'),
                'sort' => in_array($request->query('sort'), ['soonest', 'nearest', 'recommended'], true)
                    ? $request->query('sort')
                    : 'soonest',
                'mode' => in_array($request->query('mode'), ['walk', 'bike', 'transit'], true)
                    ? $request->query('mode')
                    : 'walk',
            ],
            // Veedels that actually have upcoming events — the secondary filter
            'veedelOptions' => Inertia::defer(fn () => Venue::query()
                ->whereNotNull('veedel')
                ->whereHas('events', fn ($q) => $q->visible()->where(fn ($w) => $w
                    ->where('starts_at', '>=', now()->subDay())
                    ->orWhereNotNull('recurrence')))
                ->distinct()
                ->orderBy('veedel')
                ->pluck('veedel')
                ->values()),
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
