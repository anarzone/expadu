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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

require_once __DIR__.'/common.php';
$guard = localPdo();
localVerify($guard, localSnapshot());
$guard = null;
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, static function (): void {
    config([
        'app.env' => 'testing', 'app.url' => 'https://places-local.invalid', 'app.app_domain' => 'places-local.invalid',
        'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'database.default' => 'pgsql', 'database.connections.pgsql.url' => null,
        'database.connections.pgsql.host' => getenv('DB_HOST'), 'database.connections.pgsql.port' => getenv('DB_PORT'),
        'database.connections.pgsql.database' => 'exp69_local_catalogue_20261001',
        'database.connections.pgsql.username' => getenv('DB_USERNAME'), 'database.connections.pgsql.password' => getenv('DB_PASSWORD'),
        'cache.default' => 'array', 'cache.limiter' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
        'services.composer_llm.enabled' => false, 'services.llm.key' => null, 'services.composer_llm.key' => null,
        'services.anthropic.key' => null, 'services.bureaucracy_llm.enabled' => false,
        'sentry.dsn' => null, 'sentry.traces_sample_rate' => 0,
        'logging.default' => 'local_catalogue',
        'logging.channels.local_catalogue' => ['driver' => 'single', 'path' => getenv('PLACES_LOCAL_PRIVATE').'/laravel.log', 'permission' => 0600],
        'places.automation_enabled' => false, 'places.curated_seeding_enabled' => false,
    ]);
});
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $e): never {
    file_put_contents(getenv('PLACES_LOCAL_PRIVATE').'/failure.txt', $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString());
    fwrite(STDERR, 'Local verification failed: '.$e->getMessage()."\n");
    exit(1);
});
$hub = new Hub;
$app->instance(HubInterface::class, $hub);
SentrySdk::setCurrentHub($hub);
Http::preventStrayRequests();
Queue::fake();
Mail::fake();
Notification::fake();
localCheck(app()->environment('testing') && DB::selectOne('SELECT current_database() AS db')->db === 'exp69_local_catalogue_20261001', 'Wrong native database.');
localCheck(app('cache')->store()->getStore() instanceof ArrayStore, 'Cache must remain isolated.');
DB::statement("SET TIME ZONE 'UTC'");
DB::statement('SET statement_timeout = 120000');

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
function localTypedRows(string $table): array
{
    localCheck(SnapshotPolicy::allowsTable($table), 'Unapproved local table.');

    return array_map(static fn (object $row): array => json_decode($row->raw, true, flags: JSON_THROW_ON_ERROR),
        DB::select('SELECT row_to_json(t)::text AS raw FROM "'.$table.'" t ORDER BY id'));
}
