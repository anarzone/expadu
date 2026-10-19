<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\FavouriteStopController;
use App\Models\JourneyRecent;
use App\Models\UserPlace;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\TimetableService;
use App\Services\UserLocationService;
use App\Transit\NearbyStation;
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
    public function __invoke(Request $request, TimetableService $timetable, UserLocationService $locations, NearbyStation $nearby, KvbApiService $kvb): Response
    {
        $location = $locations->context($request->user(), $request);
        $userId = $request->user()->id;
        // A picked station (`?stop=`; Today's old `?view=timetable&stop=` too).
        $stop = trim((string) $request->query('stop', ''));
        $stop = $stop === '' ? null : mb_substr($stop, 0, 120);

        return Inertia::render('timetable', [
            // Nearby: the picked station, else the closest one. An unknown
            // name keeps the closest and says so.
            'station' => Inertia::defer(function () use ($nearby, $location, $stop) {
                $picked = $stop === null ? null : $nearby->named($stop);
                if ($picked !== null) {
                    return $picked;
                }
                $closest = $location->hasOrigin() ? $nearby->closest((float) $location->lat, (float) $location->lng) : null;

                return $closest === null ? null : [...$closest, 'unknown_stop' => $stop];
            }, 'station'),
            'stop' => $stop,
            'favouriteStops' => fn () => FavouriteStopController::forUser($userId, $kvb),
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
