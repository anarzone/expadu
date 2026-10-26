<?php

namespace App\Http\Controllers;

use App\Day\DayProjection;
use App\Enums\LocationSource;
use App\Home\ComingUp;
use App\Home\FirstVisit;
use App\Home\TodayContinuation;
use App\Home\TodayDiscovery;
use App\Home\TodayNeeds;
use App\Home\TodayPaperwork;
use App\Home\TodaySaved;
use App\Http\Controllers\Api\FavouriteStopController;
use App\Models\FavouriteStop;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Personalisation\TodayTaste;
use App\Services\KvbApiService;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use App\Transit\NearbyStation;
use Carbon\CarbonImmutable;
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

        // Today loads whole, in the first response (owner, 26 Oct 2026: pages
        // load whole, never in steps). Every prop is a plain closure: a partial
        // reload (the station's 30 s poll, the day's refresh) reads only its own.
        return Inertia::render('dashboard', [
            // The person's day: plans, the trips between them and when to
            // leave for the next (DayProjection).
            'day' => fn () => app(DayProjection::class)->for($request->user(), CarbonImmutable::now(DayProjection::TIMEZONE)->format('Y-m-d'), $request),
            // Check departures: Travel's station, the picked one, else at the
            // anchor (stationAnchor); null with neither.
            'station' => function () use ($request, $locations, $nearby, $stop) {
                $picked = $stop === null ? null : $nearby->named($stop);
                if ($picked !== null) {
                    // A station they picked, for personalisation with their opt-in; never the anchor's.
                    app(SignalRecorder::class)->transit($request->user(), SignalItemType::Stop, $stop);

                    return $picked;
                }

                return $this->station($this->stationAnchor($request, $locations), $nearby);
            },
            'stop' => $stop,
            // The deadline strip and the Paperwork panel: the reviewed v2
            // plan's urgent date and next step (TodayPaperwork).
            'paperwork' => fn () => app(TodayPaperwork::class)->for($request->user()),
            // Needs you: what threatens the day and what can't wait, as push and the brief say it (TodayNeeds).
            // 'needsYou', not 'needs': that name is the shared step-free need the account menu reads.
            'needsYou' => fn () => app(TodayNeeds::class)->for($request->user()),
            // Discovery: tonight's events that fit around the plans, and the
            // everyday places open near the next plan (TodayDiscovery).
            'discovery' => fn () => app(TodayDiscovery::class)->for($request->user()),
            // Coming up: the next plans after today (ComingUp), with the day.
            'comingUp' => fn () => app(ComingUp::class)->for($request->user()),
            // Saved for later (the one Saved collection) and the Composer
            // conversation to pick up, both the person's own.
            'saved' => fn () => app(TodaySaved::class)->for($request->user()),
            'continuation' => fn () => app(TodayContinuation::class)->for($request->user()),
            // Someone new: nothing planned, saved, asked or recorded yet.
            'welcome' => fn () => app(FirstVisit::class)->for($request->user()),
            'favouriteStops' => fn () => FavouriteStopController::forUser($userId, $kvb),
            // The stops they pick most (TodayTaste, opt-in only; [] otherwise): quick picks after favourites.
            'learnedStops' => fn () => app(TodayTaste::class)->stops($request->user(), 5),
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
