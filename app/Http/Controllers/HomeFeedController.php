<?php

namespace App\Http\Controllers;

use App\Day\DayProjection;
use App\Enums\LocationSource;
use App\Home\ComingUp;
use App\Home\FirstVisit;
use App\Home\TodayContinuation;
use App\Home\TodayDiscovery;
use App\Home\TodayPaperwork;
use App\Home\TodaySaved;
use App\Http\Controllers\Api\FavouriteStopController;
use App\Models\FavouriteStop;
use App\Services\KvbApiService;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use App\Transit\NearbyStation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, UserLocationService $locations, NearbyStation $nearby, KvbApiService $kvb): Response
    {
        $userId = $request->user()->id;
        // Check departures' stop, when one was picked.
        $stop = trim((string) $request->query('stop', ''));
        $stop = $stop === '' ? null : mb_substr($stop, 0, 120);

        // Read once either way: on the page load to skip a station there's no
        // anchor for, or in the deferred read.
        $anchor = fn () => $this->stationAnchor($request, $locations);

        return Inertia::render('dashboard', [
            // The person's day: plans, the trips between them and when to
            // leave for the next (DayProjection; deferred, its own group).
            'day' => DayProjection::deferred($request),
            // Check departures: Travel's station, the picked one, else at the
            // anchor (stationAnchor). With neither there's nothing to read, so
            // no deferred request; a partial reload keeps it and answers null.
            'station' => $stop === null && $request->header('X-Inertia-Partial-Component') === null && $anchor() === null
                ? null
                : Inertia::defer(fn () => ($stop === null ? null : $nearby->named($stop)) ?? $this->station($anchor(), $nearby), 'station'),
            'stop' => $stop,
            // The deadline strip and the Paperwork panel: the reviewed v2
            // plan's urgent date and next step (TodayPaperwork).
            // Same group as the day, so the strip never pushes the day down later.
            'paperwork' => Inertia::defer(fn () => app(TodayPaperwork::class)->for($request->user()), 'day'),
            // Discovery: tonight's events that fit around the plans, and the
            // everyday places open near the next plan (TodayDiscovery).
            'discovery' => Inertia::defer(fn () => app(TodayDiscovery::class)->for($request->user()), 'discovery'),
            // Coming up: the next plans after today (ComingUp), with the day.
            'comingUp' => Inertia::defer(fn () => app(ComingUp::class)->for($request->user()), 'day'),
            // Saved for later (the one Saved collection) and the Composer
            // conversation to pick up, both the person's own.
            'saved' => Inertia::defer(fn () => app(TodaySaved::class)->for($request->user()), 'day'),
            'continuation' => Inertia::defer(fn () => app(TodayContinuation::class)->for($request->user()), 'day'),
            // Someone new: nothing planned, saved, asked or recorded yet.
            'welcome' => fn () => app(FirstVisit::class)->for($request->user()),
            'favouriteStops' => fn () => FavouriteStopController::forUser($userId, $kvb),
        ]);
    }

    /**
     * Where Check departures reads from without a picked stop: where the
     * person is (a fresh or last fix); else their first favourite stop; else
     * near Home; else near their Veedel's centre. Only the person's own fix
     * gets a walk time (NearbyStation::closestTo). Null when none is known.
     */
    private function stationAnchor(Request $request, UserLocationService $locations): string|LocationContext|null
    {
        $user = $request->user();
        $here = $locations->context($user, $request);
        if ($here->hasOrigin()) {
            return $here;
        }
        $favourite = FavouriteStop::query()->where('user_id', $user->id)->orderBy('id')->value('name');
        if ($favourite !== null) {
            return (string) $favourite;
        }
        $home = $user->places()->located()->where('category', 'home')->orderBy('sort_order')->first(['lat', 'lng']);
        if ($home !== null) {
            return new LocationContext((float) $home->lat, (float) $home->lng, LocationSource::Place, 'Home');
        }
        $area = $locations->context($user, null, $user->veedel);

        return $area->hasOrigin() ? $area : null;
    }

    /** @return array<string, mixed>|null */
    private function station(string|LocationContext|null $anchor, NearbyStation $nearby): ?array
    {
        return match (true) {
            $anchor === null => null,
            is_string($anchor) => $nearby->named($anchor),
            default => $nearby->closestTo($anchor),
        };
    }
}
