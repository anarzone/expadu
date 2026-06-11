<?php

namespace App\Providers;

use App\Composer\AnthropicConstraintParser;
use App\Composer\Contracts\ParsesConstraints;
use App\ContextEngine\Evaluators\BuergeramtEvaluator;
use App\ContextEngine\Evaluators\MarketEvaluator;
use App\ContextEngine\Evaluators\RhineEvaluator;
use App\ContextEngine\Evaluators\TransitDelayEvaluator;
use App\ContextEngine\Evaluators\TransitDisruptionEvaluator;
use App\ContextEngine\Evaluators\WeatherEvaluator;
use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\Events\Context\BuergeramtSlotsAvailable;
use App\Events\Context\MarketClosureDetected;
use App\Events\Context\RhineLevelChanged;
use App\Events\Context\ScoredActionInserted;
use App\Events\Context\TransitDelayDetected;
use App\Events\Context\TransitDisruptionDetected;
use App\Events\Context\WeatherChanged;
use App\Listeners\CreateAlertFromNotification;
use App\Transit\Contracts\RouteService;
use App\Transit\FailoverRouteService;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            RouteService::class,
            FailoverRouteService::class,
        );

        $this->app->bind(
            ParsesConstraints::class,
            AnthropicConstraintParser::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(NotificationSent::class, CreateAlertFromNotification::class);

        Event::listen(TransitDisruptionDetected::class, TransitDisruptionEvaluator::class);
        Event::listen(TransitDelayDetected::class, TransitDelayEvaluator::class);
        Event::listen(WeatherChanged::class, WeatherEvaluator::class);
        Event::listen(BuergeramtSlotsAvailable::class, BuergeramtEvaluator::class);
        Event::listen(RhineLevelChanged::class, RhineEvaluator::class);
        Event::listen(MarketClosureDetected::class, MarketEvaluator::class);

        Event::listen(ScoredActionInserted::class, ScoredActionPushDispatcher::class);

        // Enable PostGIS in parallel test databases
        ParallelTesting::setUpTestDatabase(function (string $database) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(8));

        if (app()->isProduction()) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }
    }
}
