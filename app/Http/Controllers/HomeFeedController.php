<?php

namespace App\Http\Controllers;

use App\Home\HomeFeed;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, HomeFeed $feed): Response
    {
        $user = $request->user();
        $location = app(UserLocationService::class)->resolve($user, $request);

        $weatherService = app(WeatherService::class);

        return Inertia::render('dashboard', [
            // Chips are cheap + above the fold, so they ship with the page.
            // (This first read builds the shared HomeContext.)
            'chips' => $feed->chips($user),
            // Urgency tiles and discovery rails defer — they hit the DB/feed
            // and resolve from a single shared build per request.
            'tiles' => Inertia::defer(fn () => $feed->tiles($user)),
            'rails' => Inertia::defer(fn () => $feed->rails($user)),
            'weather' => Inertia::defer(fn () => $weatherService->getCurrentWeather($location['lat'], $location['lng']), 'meta'),
        ]);
    }
}
