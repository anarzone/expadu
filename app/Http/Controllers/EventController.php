<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Venue;
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
        return Inertia::render('events', [
            'filters' => [
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
        return redirect()->route('events');
    }

    public function saved(Request $request): RedirectResponse
    {
        return redirect()->route('events');
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
