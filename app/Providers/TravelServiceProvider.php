<?php

namespace App\Providers;

use App\Services\KvbApiService;
use App\Services\TimetableService;
use App\Services\WeatherService;
use App\Transit\Contracts\RouteService;
use App\Transit\MockSample\SampleKvb;
use App\Transit\MockSample\SampleRoutes;
use App\Transit\MockSample\SampleTimetable;
use App\Transit\MockSample\SampleWeather;
use Illuminate\Support\ServiceProvider;

/**
 * Travel's local-only wiring for tools/mock-compare: the mock's sample ways
 * (SampleRoutes), weather (SampleWeather), departure boards
 * (SampleTimetable) and lift records (SampleKvb), all in
 * App\Transit\MockSample, each answering only while the local mock clock is
 * on. Nothing here runs outside local.
 */
class TravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->extend(RouteService::class, fn (RouteService $routes) => new SampleRoutes($routes));
            $this->app->bind(WeatherService::class, SampleWeather::class);
            $this->app->bind(TimetableService::class, SampleTimetable::class);
            $this->app->bind(KvbApiService::class, SampleKvb::class);
        }
    }
}
