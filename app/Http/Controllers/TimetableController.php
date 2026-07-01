<?php

namespace App\Http\Controllers;

use App\Models\UserPlace;
use App\Services\TimetableService;
use App\Services\UserLocationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Departures" page: a KVB-style live board, one stop per mode tab, from
 * the user's resolved location. The boards defer because the TRIAS real-time
 * fetch is a network round-trip — the page shell paints immediately.
 */
class TimetableController extends Controller
{
    public function __invoke(Request $request, TimetableService $timetable): Response
    {
        $location = app(UserLocationService::class)->resolve($request->user(), $request);

        return Inertia::render('timetable', [
            'boards' => Inertia::defer(fn () => $timetable->boards($location['lat'], $location['lng'])),
            // Saved places (Home / Work / pins) offered as one-tap journey
            // destinations in the "Where to?" card. Unlike the Places "From"
            // picker, coordinates are sent to the client here because the journey
            // sheet plans the route from lat/lng directly.
            'savedPlaces' => $request->user()->places()
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'category', 'emoji', 'lat', 'lng'])
                ->map(fn (UserPlace $place) => [
                    'id' => $place->id,
                    'name' => $place->name,
                    'category' => (string) $place->category,
                    'emoji' => $place->emoji,
                    'lat' => (float) $place->lat,
                    'lng' => (float) $place->lng,
                ])
                ->all(),
        ]);
    }
}
