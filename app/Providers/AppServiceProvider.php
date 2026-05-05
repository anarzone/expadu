<?php

namespace App\Providers;

use App\ContextEngine\Evaluators\BuergeramtEvaluator;
use App\ContextEngine\Evaluators\LeaveByEvaluator;
use App\ContextEngine\Evaluators\MarketEvaluator;
use App\ContextEngine\Evaluators\RhineEvaluator;
use App\ContextEngine\Evaluators\TransitDelayEvaluator;
use App\ContextEngine\Evaluators\TransitDisruptionEvaluator;
use App\ContextEngine\Evaluators\WeatherEvaluator;
use App\Events\Context\BuergeramtSlotsAvailable;
use App\Events\Context\MarketClosureDetected;
use App\Events\Context\RhineLevelChanged;
use App\Events\Context\TransitDelayDetected;
use App\Events\Context\TransitDisruptionDetected;
use App\Events\Context\UserContextChanged;
use App\Events\Context\WeatherChanged;
use App\Listeners\CreateAlertFromNotification;
use App\Models\CityNews;
use App\Models\Event as EventModel;
use App\Models\Service;
use App\Models\Spot;
use App\Models\UserPlace;
use App\Observers\EmbeddableObserver;
use App\Observers\UserPlaceObserver;
use App\Services\EmbeddingService;
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
        $this->app->singleton(EmbeddingService::class, function ($app) {
            $cfg = $app['config']->get('services.embedding');

            return new EmbeddingService(
                baseUrl: $cfg['url'],
                timeoutSec: (int) $cfg['timeout'],
                dim: (int) $cfg['dim'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(NotificationSent::class, CreateAlertFromNotification::class);

        UserPlace::observe(UserPlaceObserver::class);

        Spot::observe(EmbeddableObserver::class);
        EventModel::observe(EmbeddableObserver::class);
        CityNews::observe(EmbeddableObserver::class);
        Service::observe(EmbeddableObserver::class);

        Event::listen(TransitDisruptionDetected::class, TransitDisruptionEvaluator::class);
        Event::listen(TransitDelayDetected::class, TransitDelayEvaluator::class);
        Event::listen(WeatherChanged::class, WeatherEvaluator::class);
        Event::listen(BuergeramtSlotsAvailable::class, BuergeramtEvaluator::class);
        Event::listen(RhineLevelChanged::class, RhineEvaluator::class);
        Event::listen(MarketClosureDetected::class, MarketEvaluator::class);
        Event::listen(UserContextChanged::class, LeaveByEvaluator::class);

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
