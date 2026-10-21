<?php

namespace App\Providers;

use App\Services\WeatherService;
use App\Transit\Contracts\RouteService;
use App\Transit\MockSample\SampleRoutes;
use App\Transit\MockSample\SampleWeather;
use Illuminate\Support\ServiceProvider;

/**
 * Travel's local-only wiring for tools/mock-compare: the mock's sample ways
 * (App\Transit\MockSample\SampleRoutes) and its sample weather
 * (App\Transit\MockSample\SampleWeather), each answering only while the
 * local mock clock is on. Nothing here runs outside local.
 */
class TravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->extend(RouteService::class, fn (RouteService $routes) => new SampleRoutes($routes));
            $this->app->bind(WeatherService::class, SampleWeather::class);
        }
    }
}
