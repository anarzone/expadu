<?php

namespace App\Http\Controllers;

use App\Day\DayProjection;
use App\Home\ComingUp;
use App\Home\FirstVisit;
use App\Home\TodayContinuation;
use App\Home\TodayDiscovery;
use App\Home\TodayNeeds;
use App\Home\TodayPaperwork;
use App\Home\TodaySaved;
use App\Http\Controllers\Api\FavouriteStopController;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Services\KvbApiService;
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
        // Check departures' stop, when one was picked; else the closest.
        $stop = trim((string) $request->query('stop', ''));
        $stop = $stop === '' ? null : mb_substr($stop, 0, 120);

        return Inertia::render('dashboard', [
            // The person's day: plans, the trips between them and when to
            // leave for the next (DayProjection; deferred, its own group).
            'day' => DayProjection::deferred($request),
            // Check departures: Travel's station (the picked one, else the
            // closest; none without a location, never a saved home).
            'station' => Inertia::defer(function () use ($request, $locations, $nearby, $stop) {
                $picked = $stop === null ? null : $nearby->named($stop);
                if ($picked !== null) {
                    // A station they picked, for personalisation with their opt-in; never the closest one.
                    app(SignalRecorder::class)->transit($request->user(), SignalItemType::Stop, $stop);

                    return $picked;
                }
                $location = $locations->context($request->user(), $request);

                return $nearby->closestTo($location);
            }, 'station'),
            'stop' => $stop,
            // The deadline strip and the Paperwork panel: the reviewed v2
            // plan's urgent date and next step (TodayPaperwork).
            // Same group as the day, so the strip never pushes the day down later.
            'paperwork' => Inertia::defer(fn () => app(TodayPaperwork::class)->for($request->user()), 'day'),
            // Needs you: what threatens the day and what can't wait, as push and the brief say it (TodayNeeds).
            'needs' => Inertia::defer(fn () => app(TodayNeeds::class)->for($request->user()), 'day'),
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
}
