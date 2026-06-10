<?php

namespace App\Http\Controllers;

use App\Home\TileComposer;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, TileComposer $tiles): Response
    {
        $user = $request->user();
        $location = app(UserLocationService::class)->resolve($user, $request);
        $lat = $location['lat'];
        $lng = $location['lng'];

        $weatherService = app(WeatherService::class);

        return Inertia::render('dashboard', [
            'tiles' => Inertia::defer(fn () => $tiles->tiles($user)),
            'weather' => Inertia::defer(fn () => $weatherService->getCurrentWeather($lat, $lng), 'meta'),
        ]);
    }
}
