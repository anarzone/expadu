<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Event::query()->where('starts_at', '>', now())->orderBy('starts_at');

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($request->query('free') === 'true') {
            $query->where('is_free', true);
        }

        $events = $query->withCount('attendees')->paginate(20);

        return Inertia::render('events', [
            'events' => $events,
            'filters' => [
                'category' => $request->query('category'),
                'free' => $request->query('free'),
            ],
        ]);
    }

    public function show(Event $event): Response
    {
        $event->loadCount('attendees');
        $event->load('organiser:id,name');

        return Inertia::render('events', [
            'event' => $event,
            'events' => Event::where('starts_at', '>', now())->orderBy('starts_at')->withCount('attendees')->paginate(20),
            'filters' => [],
        ]);
    }

    public function join(Request $request, Event $event): RedirectResponse
    {
        $request->user()->attendingEvents()->syncWithoutDetaching([
            $event->id => ['joined_at' => now()],
        ]);

        return back();
    }

    public function leave(Request $request, Event $event): RedirectResponse
    {
        $request->user()->attendingEvents()->detach($event->id);

        return back();
    }

    public function saved(Request $request): Response
    {
        $events = $request->user()->attendingEvents()
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->withCount('attendees')
            ->paginate(20);

        return Inertia::render('events', [
            'events' => $events,
            'filters' => [],
            'tab' => 'saved',
        ]);
    }
}
