<?php

namespace App\Providers;

use App\Composer\AnthropicCandidateRanker;
use App\Composer\Contracts\ParsesPrompt;
use App\Composer\Contracts\RanksCandidates;
use App\Composer\HeuristicPromptParser;
use App\Composer\OpenAiCompatiblePromptParser;
use App\ContextEngine\Evaluators\MarketEvaluator;
use App\ContextEngine\Evaluators\RhineEvaluator;
use App\ContextEngine\Evaluators\TransitDelayEvaluator;
use App\ContextEngine\Evaluators\TransitDisruptionEvaluator;
use App\ContextEngine\Evaluators\WeatherEvaluator;
use App\ContextEngine\Listeners\RecordContextAlert;
use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\Events\Context\MarketClosureDetected;
use App\Events\Context\RhineLevelChanged;
use App\Events\Context\ScoredActionInserted;
use App\Events\Context\TransitDelayDetected;
use App\Events\Context\TransitDisruptionDetected;
use App\Events\Context\WeatherChanged;
use App\Jobs\ValidateMediaAssetJob;
use App\Listeners\CreateAlertFromNotification;
use App\Services\AnthropicEventClassifier;
use App\Services\ClassifiesEvents;
use App\Transit\Contracts\RouteService;
use App\Transit\FailoverRouteService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\RateLimiter;
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

        // The composer parser: heuristic by default (no key), the
        // OpenAI-compatible driver once a provider key is configured. The
        // driver always wraps the heuristic as its degradation path.
        $this->app->bind(ParsesPrompt::class, function ($app) {
            $heuristic = $app->make(HeuristicPromptParser::class);

            if (config('services.llm.driver') === 'openai' && config('services.llm.key')) {
                return new OpenAiCompatiblePromptParser($heuristic);
            }

            return $heuristic;
        });

        $this->app->bind(RanksCandidates::class, AnthropicCandidateRanker::class);

        $this->app->bind(
            ClassifiesEvents::class,
            AnthropicEventClassifier::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('composer-parse', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('composer-parse:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('composer-compose', fn (Request $request): Limit => Limit::perMinute(6)
            ->by('composer-compose:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('media-validation', fn (ValidateMediaAssetJob $job): Limit => Limit::perMinute(30)
            ->by('media-validation:'.$job->asset->provider));

        Event::listen(NotificationSent::class, CreateAlertFromNotification::class);

        Event::listen(TransitDisruptionDetected::class, TransitDisruptionEvaluator::class);
        Event::listen(TransitDelayDetected::class, TransitDelayEvaluator::class);
        Event::listen(WeatherChanged::class, WeatherEvaluator::class);
        Event::listen(RhineLevelChanged::class, RhineEvaluator::class);
        Event::listen(MarketClosureDetected::class, MarketEvaluator::class);

        Event::listen(ScoredActionInserted::class, ScoredActionPushDispatcher::class);
        Event::listen(ScoredActionInserted::class, RecordContextAlert::class);

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
