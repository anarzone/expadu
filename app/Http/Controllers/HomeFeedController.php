<?php

namespace App\Http\Controllers;

use App\Home\DiscoveryFeed;
use App\Home\PromptSuggestions;
use App\Home\TileComposer;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, TileComposer $tiles, DiscoveryFeed $discovery, PromptSuggestions $suggestions): Response
    {
        $user = $request->user();
        $location = app(UserLocationService::class)->resolve($user, $request);
        $lat = $location['lat'];
        $lng = $location['lng'];

        $weatherService = app(WeatherService::class);

        return Inertia::render('dashboard', [
            // Chips are cheap + above the fold, so they ship with the page.
            'chips' => $suggestions->for($user),
            // Urgency tiles and discovery rails defer — they hit the DB/feed.
            'tiles' => Inertia::defer(fn () => $tiles->tiles($user)),
            'rails' => Inertia::defer(fn () => $discovery->for($user)),
            'weather' => Inertia::defer(fn () => $weatherService->getCurrentWeather($lat, $lng), 'meta'),
        ]);
    }
}
