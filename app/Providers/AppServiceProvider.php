<?php

namespace App\Providers;

use App\Bureaucracy\Ai\AnthropicCaseFactExtractor;
use App\Bureaucracy\Ai\Contracts\ExtractsCaseFact;
use App\Bureaucracy\Ai\UnavailableCaseFactExtractor;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Questions\QuestionProtocol;
use App\Composer\AnthropicCandidateRanker;
use App\Composer\AnthropicPromptParser;
use App\Composer\Contracts\ParsesPrompt;
use App\Composer\Contracts\RanksCandidates;
use App\Composer\HeuristicPromptParser;
use App\ContextEngine\Evaluators\MarketEvaluator;
use App\ContextEngine\Evaluators\RhineEvaluator;
use App\ContextEngine\Evaluators\TransitDelayEvaluator;
use App\ContextEngine\Evaluators\TransitDisruptionEvaluator;
use App\ContextEngine\Evaluators\WeatherEvaluator;
use App\ContextEngine\Listeners\RecordContextAlert;
use App\ContextEngine\Listeners\RecordReservedPushSent;
use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\Events\Context\MarketClosureDetected;
use App\Events\Context\RhineLevelChanged;
use App\Events\Context\ScoredActionInserted;
use App\Events\Context\TransitDelayDetected;
use App\Events\Context\TransitDisruptionDetected;
use App\Events\Context\WeatherChanged;
use App\Jobs\ValidateMediaAssetJob;
use App\Models\User;
use App\Observers\BureaucracyAccountObserver;
use App\Observers\PersonalisationAccountObserver;
use App\Privacy\ProcessingPurpose;
use App\Services\AnthropicEventClassifier;
use App\Services\ClassifiesEvents;
use App\Support\Ssr\SsrGateway;
use App\Transit\Contracts\RouteService;
use App\Transit\FailoverRouteService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Ssr\Gateway;
use Resend\Client as ResendClient;
use Resend\Contracts\Client as ResendClientContract;
use Resend\Laravel\Exceptions\ApiKeyIsMissing;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey as ResendApiKey;
use Resend\ValueObjects\Transporter\BaseUri as ResendBaseUri;
use Resend\ValueObjects\Transporter\Headers as ResendHeaders;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ExtractsCaseFact::class, function ($app): ExtractsCaseFact {
            if (! ProcessingPurpose::FactExtraction->available()) {
                return $app->make(UnavailableCaseFactExtractor::class);
            }

            return $app->make(AnthropicCaseFactExtractor::class);
        });

        $this->app->singleton(
            RouteService::class,
            FailoverRouteService::class,
        );

        // The fact schema and the question protocol are files in the repo:
        // parse their YAML once per request, not once per service that needs
        // them (about a dozen times for one Paperwork plan).
        $this->app->singleton(FactRegistry::class);
        $this->app->singleton(QuestionProtocol::class);

        // Inertia SSR with a short timeout instead of Laravel's 30 s default.
        $this->app->singleton(Gateway::class, SsrGateway::class);

        // The composer parser: heuristic by default, Claude once the anthropic
        // driver and a key are configured. Claude always wraps the heuristic
        // as its degradation path.
        $this->app->bind(ParsesPrompt::class, function ($app) {
            $heuristic = $app->make(HeuristicPromptParser::class);

            if (ProcessingPurpose::ComposerParse->available()) {
                return new AnthropicPromptParser($heuristic);
            }

            return $heuristic;
        });

        $this->app->bind(RanksCandidates::class, AnthropicCandidateRanker::class);

        $this->app->bind(
            ClassifiesEvents::class,
            AnthropicEventClassifier::class,
        );

        $this->bindResendClient();
    }

    /**
     * resend-laravel builds its client with a bare Guzzle instance — no connect
     * or total timeout — so a stalled Resend call would hang the request or
     * queue worker. Rebind it (same wiring as `Resend::client()`) with bounded
     * timeouts; the package's `resend` alias follows the contract binding.
     */
    private function bindResendClient(): void
    {
        $this->app->singleton(ResendClientContract::class, function (): ResendClient {
            $apiKey = config('resend.api_key') ?? config('services.resend.key');

            if (! is_string($apiKey)) {
                throw ApiKeyIsMissing::create();
            }

            return new ResendClient(new HttpTransporter(
                new GuzzleClient([
                    'connect_timeout' => config('services.resend.connect_timeout'),
                    'timeout' => config('services.resend.timeout'),
                ]),
                ResendBaseUri::from(getenv('RESEND_BASE_URL') ?: 'api.resend.com'),
                ResendHeaders::withAuthorization(ResendApiKey::from($apiKey)),
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        User::observe(BureaucracyAccountObserver::class);
        User::observe(PersonalisationAccountObserver::class);

        // CreateAlertFromNotification is registered by listener discovery.
        Event::listen(NotificationSent::class, RecordReservedPushSent::class);

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

        // Emailed confirmation codes (EmailConfirmation): a few per account,
        // ten a day (each send is five more tries), and a cap per address so
        // one machine can't mail many inboxes.
        RateLimiter::for('email-confirmation', fn (Request $request): array => [
            Limit::perMinutes(10, 3)->by('email-confirmation:user:'.$request->user()?->getAuthIdentifier()),
            Limit::perDay(10)->by('email-confirmation:user-day:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour(20)->by('email-confirmation:ip:'.$request->ip()),
        ]);

        // User-generated public content (reviews) — the classic spam target,
        // stacked under app-writes with a much tighter budget.
        RateLimiter::for('ugc', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('ugc:'.$userOrIp($request)));

        // "Good for laptop work?" answers: 20 a day per person, 3 changes per place a day.
        RateLimiter::for('place-answers', fn (Request $request): array => [
            Limit::perDay(20)->by('place-answers:'.$request->user()?->getAuthIdentifier()),
            // The throttle runs before route binding: the place may still be its id.
            Limit::perDay(3)->by('place-answers:'.$request->user()?->getAuthIdentifier().':'.(is_object($request->route('spot')) ? $request->route('spot')->getKey() : (string) $request->route('spot'))),
        ]);

        RateLimiter::for('composer-parse', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('composer-parse:'.$request->user()?->getAuthIdentifier()));
        // The page polls about once a second while the assistant works (3–6 s).
        RateLimiter::for('composer-progress', fn (Request $request): Limit => Limit::perMinute(120)
            ->by('composer-progress:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('composer-compose', fn (Request $request): Limit => Limit::perMinute(6)
            ->by('composer-compose:'.$request->user()?->getAuthIdentifier()));
        // Bureaucracy v2 limiters are named so each budget keeps its own key: unnamed
        // `throttle:N,M` middleware shares one per-user signature, so a stacked group
        // limit's shorter decay would reset the hourly caps on the routes below.
        $bureaucracyKey = fn (string $purpose, Request $request): string => 'bureaucracy-'.$purpose.':'.$userOrIp($request);
        RateLimiter::for('bureaucracy-v2', fn (Request $request): Limit => Limit::perMinute(60)->by($bureaucracyKey('v2', $request)));
        RateLimiter::for('bureaucracy-extract', fn (Request $request): Limit => Limit::perMinute(20)->by($bureaucracyKey('extract', $request)));
        RateLimiter::for('bureaucracy-ask', fn (Request $request): Limit => Limit::perMinute(10)->by($bureaucracyKey('ask', $request)));
        RateLimiter::for('bureaucracy-invitations', fn (Request $request): Limit => Limit::perHour(10)->by($bureaucracyKey('invitations', $request)));
        RateLimiter::for('bureaucracy-dependents', fn (Request $request): Limit => Limit::perHour(5)->by($bureaucracyKey('dependents', $request)));
        // Batched impressions/detail views (≤ 50 each): one batch every 5 s at most.
        RateLimiter::for('signals', fn (Request $request): Limit => Limit::perMinute(12)->by('signals:'.$userOrIp($request)));
        RateLimiter::for('taste-profile', fn (Request $request): Limit => Limit::perMinute(30)->by('taste-profile:'.$userOrIp($request)));
        RateLimiter::for('privacy-processing', fn (Request $request): Limit => Limit::perMinute(60)->by('privacy-processing:'.$userOrIp($request)));
        RateLimiter::for('media-validation', fn (ValidateMediaAssetJob $job): Limit => Limit::perMinute(30)
            ->by('media-validation:'.$job->asset->provider));
    }
}
