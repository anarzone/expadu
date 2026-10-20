<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\FavouriteStopController;
use App\Models\JourneyRecent;
use App\Models\UserPlace;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\UserLocationService;
use App\Transit\NearbyStation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Travel: Nearby, the closest (or a picked) station by boarding area, with
 * the person's favourite stops and saved places. The station defers because
 * the live departures are a network round-trip: the page paints at once.
 */
class TimetableController extends Controller
{
    public function __invoke(Request $request, UserLocationService $locations, NearbyStation $nearby, KvbApiService $kvb): Response
    {
        $location = $locations->context($request->user(), $request);
        $userId = $request->user()->id;
        // A picked station (`?stop=`; Today's old `?view=timetable&stop=` too).
        $stop = trim((string) $request->query('stop', ''));
        $stop = $stop === '' ? null : mb_substr($stop, 0, 120);

        return Inertia::render('timetable', [
            // Nearby: the picked station, else the closest one. An unknown
            // name keeps the closest and says so.
            'station' => Inertia::defer(function () use ($nearby, $location, $stop, $userId) {
                $picked = $stop === null ? null : $nearby->named($stop);
                if ($picked !== null) {
                    return $picked;
                }
                // Never a saved home or the city centre: no location, no station.
                $closest = $location->hasOrigin() ? $nearby->closest((float) $location->lat, (float) $location->lng) : null;
                if ($closest !== null) {
                    // Debug trail for "wrong station" reports: the station, its
                    // lines and why it was chosen, without where the person was.
                    GtfsDepartureService::logDepartureDebug($userId, 'timetable', [
                        'stop_name' => $closest['name'],
                        'source' => $closest['source'],
                        'departures' => array_map(fn (array $line) => ['line' => $line['line']], $closest['lines']),
                    ], locationSource: $location->source->value);
                }

                return $closest === null ? null : [...$closest, 'unknown_stop' => $stop];
            }, 'station'),
            'stop' => $stop,
            'favouriteStops' => fn () => FavouriteStopController::forUser($userId, $kvb),
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
