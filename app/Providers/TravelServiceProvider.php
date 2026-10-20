<?php

namespace App\Providers;

use App\Transit\Contracts\RouteService;
use App\Transit\MockSample\SampleRoutes;
use Illuminate\Support\ServiceProvider;

/**
 * Travel's local-only wiring: the mock's sample ways for tools/mock-compare
 * (App\Transit\MockSample\SampleRoutes answers only while the local mock
 * clock is on). Nothing here runs outside local.
 */
class TravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local')) {
            $this->app->extend(RouteService::class, fn (RouteService $routes) => new SampleRoutes($routes));
        }
    }
}
