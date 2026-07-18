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
        $this->configureRateLimiting();

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

    /**
     * Central throttle policy. Buckets key on the authenticated user where
     * possible (accounts are expensive to rotate; IPs are not) and fall back
     * to the client IP for guests. Page reads stay effectively unthrottled so
     * humans never notice — the walls target mutation floods, auth abuse and
     * the expensive MOTIS-backed lookups.
     */
    protected function configureRateLimiting(): void
    {
        $userOrIp = fn (Request $request): string => (string) ($request->user()?->getAuthIdentifier() ?: $request->ip());

        // Web-wide ceiling against single-source floods and scrapers. Local
        // and testing are exempt: dev servers and browser suites burst far
        // above any human pattern, and there is no abuse to fend off there.
        RateLimiter::for('global', function (Request $request) use ($userOrIp): Limit {
            if (app()->environment(['local', 'testing'])) {
                return Limit::none();
            }

            return Limit::perMinute(300)->by('global:'.$userOrIp($request));
        });

        // Every Fortify POST (register, forgot/reset password, confirm
        // password, 2FA management) shares one per-IP budget. This caps bot
        // signups, reset-mail bombing through Resend, and cross-account
        // credential spraying that the per-email login limiter cannot see.
        // Safe methods (login page, QR code, status checks) stay free.
        RateLimiter::for('fortify-forms', fn (Request $request): Limit => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(30)->by('fortify:'.$request->ip()));

        // Mutations from the signed-in app — applied group-wide, so every
        // POST/PUT/PATCH/DELETE is covered without per-route opt-ins. One
        // write per second sustained is generous for a human, a wall for a
        // script. GETs pass through untouched.
        RateLimiter::for('app-writes', fn (Request $request): Limit => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(60)->by('writes:'.$userOrIp($request)));

        // Typeahead + geocoding endpoints hit MOTIS/Photon per request; a
        // debounced client peaks around 2/s while typing.
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('search:'.$userOrIp($request)));

        // Full journey plans are the most expensive MOTIS calls we make.
        RateLimiter::for('journey', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('journey:'.$userOrIp($request)));

        // OAuth redirect/callback — a human does this once or twice a visit.
        RateLimiter::for('social', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('social:'.$request->ip()));

        // User-generated public content (reviews) — the classic spam target,
        // stacked under app-writes with a much tighter budget.
        RateLimiter::for('ugc', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('ugc:'.$userOrIp($request)));

        RateLimiter::for('composer-parse', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('composer-parse:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('composer-compose', fn (Request $request): Limit => Limit::perMinute(6)
            ->by('composer-compose:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('media-validation', fn (ValidateMediaAssetJob $job): Limit => Limit::perMinute(30)
            ->by('media-validation:'.$job->asset->provider));
    }
}
