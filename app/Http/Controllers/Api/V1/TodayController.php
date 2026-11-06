<?php

namespace App\Http\Controllers\Api\V1;

use App\Day\DayProjection;
use App\Home\ComingUp;
use App\Home\TodayDiscovery;
use App\Home\TodayNeeds;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TodayResource;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    /**
     * Today for the iPhone: the same day, notes and ideas the web's Today reads
     * (HomeFeedController), in one answer shaped for the app.
     */
    public function show(Request $request, DayProjection $day, TodayNeeds $needs, TodayDiscovery $discovery, ComingUp $comingUp, WeatherService $weather): TodayResource
    {
        // Where the phone is, so the Leave card can say when to go: the trip starts there.
        $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $user = $request->user();

        return new TodayResource([
            'day' => $day->for($user, CarbonImmutable::now(DayProjection::TIMEZONE)->format('Y-m-d'), $request),
            'needs' => $needs->for($user),
            'discovery' => $discovery->for($user, shown: true),
            'coming_up' => $comingUp->for($user),
            'weather' => $weather->getCurrentWeather(),
        ]);
    }
}
