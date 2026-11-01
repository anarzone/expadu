<?php

declare(strict_types=1);

use App\Enums\LocationSource;
use App\Enums\TransportMode;
use App\Models\User;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/HistoricalCatalogueSnapshot.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, static function (): void {
    localCheck(config('app.env') === 'local'
        && config('database.default') === 'pgsql'
        && config('database.connections.pgsql.host') === '127.0.0.1'
        && config('database.connections.pgsql.database') === 'expadu'
        && ! config('database.connections.pgsql.url'), 'Only the owned local expadu database is allowed.');
    config([
        'app.url' => 'https://catalogue-review.invalid', 'app.app_domain' => 'catalogue-review.invalid',
        'cache.default' => 'array', 'cache.limiter' => 'array', 'session.driver' => 'array',
        'services.composer_llm.enabled' => false, 'services.llm.key' => null, 'services.composer_llm.key' => null,
        'services.anthropic.key' => null, 'services.bureaucracy_llm.enabled' => false,
        'sentry.dsn' => null, 'sentry.traces_sample_rate' => 0, 'logging.default' => 'null',
        'places.automation_enabled' => false, 'places.curated_seeding_enabled' => false,
    ]);
});
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
});
$hub = new Hub;
$app->instance(HubInterface::class, $hub);
SentrySdk::setCurrentHub($hub);
Http::preventStrayRequests();
Queue::fake();
Bus::fake();
Mail::fake();
Notification::fake();
localCheck(app('cache')->store()->getStore() instanceof ArrayStore, 'Cache must remain isolated.');
localCheck(DB::connection()->getConfig('database') === 'expadu'
    && DB::connection()->getConfig('host') === '127.0.0.1', 'Wrong native database.');
DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
DB::statement("SET TIME ZONE 'UTC'");
DB::statement('SET statement_timeout = 120000');
localCheck(DB::selectOne('SELECT current_database() AS db')->db === 'expadu', 'Wrong connected database.');

function localCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new DomainException($message);
    }
}

function localUser(): User
{
    app()->instance(UserLocationService::class, new class extends UserLocationService
    {
        public function context(User $user, ?Request $request = null, ?string $fallbackArea = null): LocationContext
        {
            return new LocationContext(null, null, LocationSource::None);
        }
    });
    $user = new User;
    $user->forceFill(['id' => -9223372036854770001, 'email_verified_at' => now(), 'onboarded_at' => now(), 'transport_mode' => TransportMode::Walk]);

    return $user;
}

function localApi(string $path, User $user): array
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    $request = Request::create($path, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_HOST' => config('app.app_domain'), 'HTTPS' => 'on']);
    $request->setUserResolver(static fn () => $user);
    $response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    localCheck($response->getStatusCode() === 200, 'Local API failed: '.$path.' '.$response->getStatusCode());

    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
}
