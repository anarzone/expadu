<?php

namespace App\Http\Controllers;

use App\Day\DayProjection;
use App\Http\Controllers\Api\FavouriteStopController;
use App\Models\FavouriteStop;
use App\Models\JourneyRecent;
use App\Models\UserPlace;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use App\Transit\EventHandoff;
use App\Transit\NearbyStation;
use App\Transit\PlaceHandoff;
use Carbon\CarbonImmutable;
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

        // Their favourite stations' boards, built after the response, so a tap
        // on one finds it ready (only on a visit, not a poll).
        if (! $request->hasHeader('X-Inertia-Partial-Data')) {
            $favourites = FavouriteStop::query()->where('user_id', $userId)->pluck('name')->all();
            if ($favourites !== []) {
                dispatch(function () use ($favourites) {
                    foreach ($favourites as $name) {
                        app(NearbyStation::class)->warm($name);
                    }
                })->afterResponse();
            }
        }

        return Inertia::render('timetable', [
            // Nearby: the picked station, else the closest one. An unknown
            // name keeps the closest and says so.
            'station' => Inertia::defer(function () use ($nearby, $location, $stop, $userId) {
                $picked = $stop === null ? null : $nearby->named($stop);
                if ($picked !== null) {
                    return $picked;
                }
                // Never a saved home or the city centre: no location, no station.
                $closest = $nearby->closestTo($location);
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
            // Events' "Open in Travel": the occurrence by its id and local start
            // (`context`, `date`, `arrive`), resolved here so its place never
            // sits in the address.
            'handoffEvent' => fn () => $request->filled('context')
                ? app(EventHandoff::class)->forLocal($request->query('context'), $request->query('date'), $request->query('arrive'), CarbonImmutable::now())
                : null,
            // A place's "Get there": the place by its id, resolved here too.
            'handoffPlace' => fn () => $request->filled('place')
                ? app(PlaceHandoff::class)->for($request->query('place'), $request->query('part'))
                : null,
            'favouriteStops' => fn () => FavouriteStopController::forUser($userId, $kvb),
            // The person's own step-free need: Plan a trip and Nearby start
            // with Step-free on. Hidden from the user's serialisation, so it's
            // only ever read here, for them.
            'stepFreeNeed' => fn () => (bool) $request->user()->needs_step_free,
            // Your next thing and My day: the shared server-side day projection.
            'day' => DayProjection::deferred($request),
            // My day's forecast for the rest of today, by the hour.
            'dayWeather' => Inertia::defer(fn () => self::dayWeather(app(WeatherService::class)), 'day'),
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

    /**
     * The city's forecast from this hour to the end of today; unavailable
     * (no hours) when the forecast has none, never a guess.
     *
     * @return array{available: bool, hours: list<array{at: string, icon: ?string, condition: ?string, temperature: ?float, precip_probability: ?int}>}
     */
    private static function dayWeather(WeatherService $weather): array
    {
        $now = CarbonImmutable::now('Europe/Berlin');
        $hours = [];
        foreach ($weather->getDayForecast($now->format('Y-m-d'))['hours'] ?? [] as $hour) {
            if (! is_string($hour['time'] ?? null)) {
                continue;
            }
            $at = CarbonImmutable::parse($hour['time'])->setTimezone('Europe/Berlin');
            if ($at->lessThan($now->startOfHour())) {
                continue;
            }
            $hours[] = [
                'at' => $at->toIso8601String(),
                'icon' => $hour['icon'] ?? null,
                'condition' => $hour['condition'] ?? null,
                'temperature' => isset($hour['temperature']) ? (float) $hour['temperature'] : null,
                'precip_probability' => isset($hour['precip_probability']) ? (int) $hour['precip_probability'] : null,
            ];
        }

        return ['available' => $hours !== [], 'hours' => $hours];
    }
}
