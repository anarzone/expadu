<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfilePageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // Events the user is attending (upcoming)
        $goingEvents = $user->attendingEvents()
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'emoji' => $e->emoji,
                'date' => $e->starts_at->format('D d M'),
                'time' => $e->starts_at->format('H:i'),
                'venue' => $e->location_name,
            ]);

        // Past events
        $pastEvents = $user->attendingEvents()
            ->where('starts_at', '<', now())
            ->orderByDesc('starts_at')
            ->limit(10)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'emoji' => $e->emoji,
                'date' => $e->starts_at->format('D d M'),
                'time' => $e->starts_at->format('H:i'),
                'venue' => $e->location_name,
            ]);

        // Task progress
        $taskProgress = $user->userTasks()->whereNotNull('completed_at')->count();
        $totalTasks = $user->userTasks()->count();

        return Inertia::render('profile', [
            'userPlaces' => $user->places()
                ->orderByRaw("CASE category WHEN 'home' THEN 0 WHEN 'work' THEN 1 ELSE 2 END")
                ->orderBy('sort_order')
                ->get(),
            'goingEvents' => $goingEvents,
            'pastEvents' => $pastEvents,
            'stats' => [
                'events_joined' => $user->attendingEvents()->count(),
                'tasks_completed' => $taskProgress,
                'tasks_total' => $totalTasks,
                // Days SINCE arrival (arrival → now). The operand order matters:
                // Carbon returns a signed diff, so now()->diffInDays(arrival)
                // yields a negative for a past arrival ("-267 DAYS"). Clamp so a
                // not-yet-arrived date never shows negative.
                'days_in_germany' => $user->arrival_date
                    ? max(0, (int) $user->arrival_date->diffInDays(now()))
                    : null,
            ],
            'interests' => $user->interests ?? [],
        ]);
    }
}
