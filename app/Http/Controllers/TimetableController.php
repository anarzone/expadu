<?php

namespace App\Http\Controllers;

use App\Day\DayProjection;
use App\Models\JourneyRecent;
use App\Models\UserPlace;
use App\Services\GtfsDepartureService;
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
    public function __invoke(Request $request, TimetableService $timetable, UserLocationService $locations): Response
    {
        $location = $locations->context($request->user(), $request);
        $userId = $request->user()->id;

        return Inertia::render('timetable', [
            'boards' => Inertia::defer(function () use ($timetable, $location, $userId) {
                if (! $location->hasOrigin()) {
                    return ['all' => null, 'tram' => null, 'bus' => null, 'rail' => null];
                }

                $boards = $timetable->boards($location->lat, $location->lng);

                // Debug trail: record where the board rooted and WHY (location
                // source) so a "wrong station" report is diagnosable per user.
                GtfsDepartureService::logDepartureDebug(
                    $userId,
                    'timetable',
                    $boards['all'] ?? ['departures' => []],
                    $location->lat,
                    $location->lng,
                    $location->source->value,
                );

                return $boards;
            }),
            'locationRequired' => ! $location->hasOrigin(),
            // Your next thing and My day: the shared server-side day projection.
            'day' => DayProjection::deferred($request),
            // Saved places (Home / Work / pins) offered as one-tap journey
            // destinations in the "Where to?" card. Unlike the Places "From"
            // picker, coordinates are sent to the client here because the journey
            // sheet plans the route from lat/lng directly. This and recents are
            // lazy: the board polls every 30 s with only=boards, and an eager
            // query here ran on every poll for a prop the poll throws away.
            'savedPlaces' => fn () => $request->user()->places()
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
            // Recent destinations round out the quick-launch pills under
            // "Where to?" (saved places first, recents fill the rest).
            'recentDestinations' => fn () => JourneyRecent::query()
                ->where('user_id', $request->user()->id)
                ->where('role', 'destination')
                ->orderByDesc('last_used_at')
                ->limit(4)
                ->get()
                ->map(fn (JourneyRecent $recent) => [
                    'name' => $recent->name,
                    'area' => $recent->area,
                    'lat' => $recent->lat,
                    'lng' => $recent->lng,
                ])
                ->all(),
        ]);
    }
}
