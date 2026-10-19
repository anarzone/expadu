<?php

use App\Enums\LocationSource;
use App\Enums\TransportMode;
use App\Http\Controllers\Api\PerformancePlacesController;
use App\Http\Controllers\Api\PlacesController;
use App\Models\Spot;
use App\Models\User;
use App\Places\PerformancePlaceFactsV2;
use App\Places\PlaceFacts;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

// There is deliberately no commit mode. Staging execution requires prior approval.
if ($argc !== 2 || ! in_array($argv[1], ['--staging'], true)) {
    fwrite(STDERR, "Choose exactly --staging. This program always rolls back.\n");
    exit(1);
}
$isStaging = $argv[1] === '--staging';
$input = getenv('PLACES_REHEARSAL_INPUT');
if (! is_string($input) || ! is_dir($input)) {
    throw new RuntimeException('An explicit prepared input directory is required.');
}
umask(0077);
$privateExceptionHandler = static function (Throwable $error) use ($input): never {
    file_put_contents($input.'/batch-v2-candidate-performance-failure-private.txt', $error::class.': '.$error->getMessage().PHP_EOL);
    fwrite(STDERR, "Rehearsal failed during preflight; details stay in the evidence directory.\n");
    exit(1);
};
set_exception_handler($privateExceptionHandler);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$privateConfiguration = [
    // Providers construct the rate limiter during boot; isolate its store first.
    'cache.default' => 'array',
    'cache.limiter' => 'array',
    'queue.default' => 'sync',
    'session.driver' => 'array',
    'services.composer_llm.enabled' => false,
    'sentry.dsn' => null,
    'sentry.traces_sample_rate' => 0,
    'logging.default' => 'places_rehearsal_private',
    'logging.channels.places_rehearsal_private' => ['driver' => 'single', 'path' => $input.'/laravel-private.log', 'permission' => 0600],
];
$app->afterBootstrapping(LoadConfiguration::class, static function () use ($privateConfiguration): void {
    config($privateConfiguration);
});
try {
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $error) {
    $privateExceptionHandler($error);
}
set_exception_handler($privateExceptionHandler);
// Kernel-reported API exceptions must not use the deployed Sentry transport.
$privateHub = new Hub;
$app->instance(HubInterface::class, $privateHub);
SentrySdk::setCurrentHub($privateHub);

function stagingPackCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function stagingPackRows(string $table): array
{
    $allowed = ['spots', 'veedels', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_destination_reviews', 'place_reconciliations', 'media_assets', 'media_attachments'];
    stagingPackCheck(in_array($table, $allowed, true), 'Unexpected table requested.');

    return array_map(static fn ($row) => json_decode($row->raw, true, flags: JSON_THROW_ON_ERROR), DB::select('SELECT row_to_json(t)::text AS raw FROM '.$table.' t ORDER BY id'));
}

function stagingPackDigest(string $table): array
{
    $rows = stagingPackRows($table);

    return ['count' => count($rows), 'sha256' => preparedPlaceRowFingerprint($rows)];
}

function stagingPackApi(string $uri, User $user): array
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_HOST' => config('app.app_domain'), 'HTTPS' => 'on']);
    $request->setUserResolver(static fn () => $user);
    $response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    stagingPackCheck($response->getStatusCode() === 200, 'Places API response status: '.$response->getStatusCode());

    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
}

stagingPackCheck(app()->environment($isStaging ? 'staging' : 'testing'), 'Unexpected application environment.');
stagingPackCheck(SentrySdk::getCurrentHub()->getClient() === null && $app->make(HubInterface::class)->getClient() === null, 'Error reporting transport is not isolated.');
stagingPackCheck(DB::selectOne('SELECT current_database() AS name')->name === ($isStaging ? 'expadu_staging' : 'exp72_ready_20260928'), 'Unexpected database.');
if ($isStaging) {
    stagingPackCheck(rtrim((string) config('app.url'), '/') === 'https://app.staging.expadu.com', 'Unexpected staging URL.');
}
$checksums = json_decode(file_get_contents($input.'/checksums.json'), true, flags: JSON_THROW_ON_ERROR);
$reviewedChecksums = [
    'records.jsonl' => 'ecfc2777acf6e91d8e574394418120163e725d2b432201179fbcc60fd20f605a',
    'manifest.json' => 'fb9a648002cc6130d38b9f658da6558cff609caa5b511bdcdf996a58566e1ce9',
    'apply-pack.php' => 'cd2fc73545d2255d64a6ac80f590df5fb54c9db458022bfc36444af47e8b5a66',
    'fingerprints.json' => 'e63adc8463317a11a74b46653e1e1d63513fd79eb38e089ac02713edb8e89f2f',
];
stagingPackCheck($checksums === $reviewedChecksums, 'Inputs differ from the separately reviewed package, importer or baseline.');
foreach ($checksums as $file => $hash) {
    stagingPackCheck(hash_file('sha256', $input.'/'.$file) === $hash, 'Prepared input changed: '.$file);
}
stagingPackCheck($checksums['records.jsonl'] === 'ecfc2777acf6e91d8e574394418120163e725d2b432201179fbcc60fd20f605a', 'Unreviewed package.');
require $input.'/apply-pack.php';
$manifest = json_decode(file_get_contents($input.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$expected = json_decode(file_get_contents($input.'/fingerprints.json'), true, flags: JSON_THROW_ON_ERROR);
$records = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($input.'/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
stagingPackCheck(count($records) === 4643 && count($expected) === 4643 && count(array_unique(array_column($records, 'key'))) === 4643, 'Unexpected package identities.');
$limiterCache = (new ReflectionProperty(RateLimiter::class, 'cache'))->getValue(app(RateLimiter::class));
stagingPackCheck($limiterCache->getStore() instanceof ArrayStore, 'Rate limiter cache is not isolated.');
stagingPackCheck(app('cache')->store()->getStore() instanceof ArrayStore, 'Default cache is not isolated.');
Http::preventStrayRequests();
Queue::fake();
$app->instance(UserLocationService::class, new class extends UserLocationService
{
    public function context(User $user, ?Request $request = null, ?string $fallbackArea = null): LocationContext
    {
        return new LocationContext(null, null, LocationSource::None);
    }
});
$user = new User;
$user->forceFill(['id' => -9223372036854770001, 'email_verified_at' => now(), 'onboarded_at' => now(), 'transport_mode' => TransportMode::Walk]);

$codeHashes = ['PerformanceDestinationGrouping.php' => 'bbafa756d0091cecf26e8960816d36a049fcb9ecdb891a18100065dea8c11262', 'PerformancePlacesController.php' => 'c2c15943c451d241dc39b160dcd7c92335ded26890c952b261b0779cf3d04539', 'PerformancePlaceFactsV2.php' => '2498da3e8f4e1fccab3c490905f9b3abfaf74df31f102c597834caf7ac382a8a'];
foreach ($codeHashes as $name => $hash) {
    stagingPackCheck(hash_file('sha256', $input.'/'.$name) === $hash, 'Candidate code checksum mismatch');
    require $input.'/'.$name;
}
Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-09-29 12:00', 'Europe/Berlin'));
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 12:00', 'Europe/Berlin'));
$originalFacts = app(PlaceFacts::class);
$candidateFacts = app(PerformancePlaceFactsV2::class);
$originalController = app(PlacesController::class);
$candidateController = app(PerformancePlacesController::class);
$routes = array_values(array_filter(app('router')->getRoutes()->getRoutes(), static fn ($route) => ltrim((string) $route->getControllerClass(), '\\') === PlacesController::class));
stagingPackCheck(count($routes) >= 2, 'Places routes not found');
$selectController = static function (bool $candidate) use ($routes, $originalController, $candidateController, $originalFacts, $candidateFacts): void {
    $selectedFacts = $candidate ? $candidateFacts : $originalFacts;
    app()->instance(PlaceFacts::class, $selectedFacts);
    stagingPackCheck(app(PlaceFacts::class) === $selectedFacts, 'Access-policy binding did not take effect');
    $selected = $candidate ? $candidateController : $originalController;
    app()->instance(PlacesController::class, $selected);
    foreach ($routes as $route) {
        $route->flushController();
        stagingPackCheck($route->getController() === $selected, 'Controller replacement did not take effect');
    }
};
$cases = ['food_first' => '/api/places?category=food_drink&page=1', 'food_second' => '/api/places?category=food_drink&page=2'];
$tables = ['spots', 'veedels', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_destination_reviews', 'place_reconciliations', 'media_assets', 'media_attachments'];
$report = ['started_at' => gmdate('c'), 'environment' => 'staging', 'mode' => 'rollback_only_batch_v2_candidate_performance', 'verification_code_sha256' => hash_file('sha256', __FILE__), 'candidate_code_sha256' => $codeHashes, 'package_sha256' => $checksums['records.jsonl'], 'samples_per_case' => 30, 'measurement_scope' => 'Laravel HTTP kernel and middleware; controller and equivalent access policy replaced in verifier process only; no external network round trip', 'raw_records_exported' => false, 'production_changed' => false, 'catalogue_commit_attempted' => false, 'running_application_files_changed' => false, 'jit_configuration_changed' => false, 'fixed_request_time' => '2026-09-29T12:00:00+02:00', 'variants' => []];
$write = static function () use (&$report, $input): void {
    file_put_contents($input.'/batch-v2-candidate-performance-progress.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
};
$measure = static function (string $variant, object $expectedController) use (&$report, $write, $cases, $user, $originalController, $originalFacts, $candidateFacts): void {
    $expectedFacts = $expectedController === $originalController ? $originalFacts : $candidateFacts;
    foreach ($cases as $name => $uri) {
        $times = [];
        $fingerprint = null;
        for ($i = 0; $i < 30; $i++) {
            $start = hrtime(true);
            $body = stagingPackApi($uri, $user);
            $times[] = (hrtime(true) - $start) / 1e6;
            stagingPackCheck(app('router')->current()->getController() === $expectedController, 'HTTP request used wrong controller');
            stagingPackCheck(app(PlaceFacts::class) === $expectedFacts, 'HTTP request used wrong facts policy');
            $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
            stagingPackCheck($fingerprint === null || $fingerprint === $hash, 'Repeated API payload changed');
            $fingerprint = $hash;
        }
        $sorted = $times;
        sort($sorted);
        $report['variants'][$variant][$name] = ['samples_ms' => $times, 'first_read_ms' => $times[0], 'median_ms' => ($sorted[14] + $sorted[15]) / 2, 'p95_ms' => $sorted[28], 'max_ms' => $sorted[29], 'response_total' => $body['meta']['total'], 'public_place_ids' => array_column($body['data'], 'id'), 'payload_sha256' => $fingerprint, 'controller_class' => get_class($expectedController)];
        echo json_encode(['phase' => $variant, 'case' => $name, 'p95_ms' => $sorted[28], 'total' => $body['meta']['total']]).PHP_EOL;
        $write();
    }
    stagingPackCheck(array_intersect($report['variants'][$variant]['food_first']['public_place_ids'], $report['variants'][$variant]['food_second']['public_place_ids']) === [], 'Pagination duplicated IDs');
};

$measureNarrow = static function (string $variant, object $expectedFacts, object $expectedController, int $id) use (&$report, $write, $user): void {
    foreach (['key_lookup', 'detail_api'] as $case) {
        $times = [];
        $hash = null;
        for ($i = 0; $i < 30; $i++) {
            $start = hrtime(true);
            $value = $case === 'key_lookup'
                ? Spot::query()->recommendationEligible()->whereKey($id)->exists()
                : stagingPackApi('/api/places/'.$id, $user);
            $times[] = (hrtime(true) - $start) / 1e6;
            stagingPackCheck(app(PlaceFacts::class) === $expectedFacts, 'Narrow request used wrong access policy');
            if ($case === 'detail_api') {
                stagingPackCheck(app('router')->current()->getController() === $expectedController, 'Narrow detail used wrong controller');
                stagingPackCheck($value['data']['id'] === $id, 'Narrow detail identity differs');
            } else {
                stagingPackCheck($value === true, 'Known listing identity no longer eligible');
            }
            $current = hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
            stagingPackCheck($hash === null || $hash === $current, 'Repeated narrow result changed');
            $hash = $current;
        }
        sort($times);
        $report['narrow_queries'][$variant][$case] = ['public_place_id' => $id, 'samples' => 30, 'median_ms' => ($times[14] + $times[15]) / 2, 'p95_ms' => $times[28], 'max_ms' => $times[29], 'payload_sha256' => $hash];
        $write();
    }
};
$stage = 'preflight';
$statisticsTouched = false;
try {
    DB::beginTransaction();
    DB::statement('SET LOCAL statement_timeout = 120000');
    DB::statement('SET LOCAL lock_timeout = 5000');
    DB::statement('LOCK TABLE '.implode(', ', $tables).' IN SHARE ROW EXCLUSIVE MODE');
    foreach ($tables as $table) {
        $before[$table] = stagingPackDigest($table);
    }
    $beforeRows = array_column(stagingPackRows('spots'), null, 'id');
    $sources = [];
    foreach ($beforeRows as $row) {
        $sources[$row['source'].':'.$row['source_id']][] = $row['id'];
    }
    foreach ($records as $record) {
        $id = $record['existing_id'];
        stagingPackCheck(isset($expected[$record['key']]) && $expected[$record['key']]['id'] === $id, 'Unexpected identity');
        stagingPackCheck($id === null ? ! isset($sources[$record['key']]) : (isset($beforeRows[$id]) && preparedPlaceRowFingerprint($beforeRows[$id]) === $expected[$record['key']]['sha256']), 'Stale package identity');
    }
    $report['database_settings'] = DB::select("SELECT name,setting FROM pg_settings WHERE name IN ('jit','jit_above_cost','jit_inline_above_cost','jit_optimize_above_cost','server_version') ORDER BY name");
    DB::beginTransaction();
    try {
        $statisticsTouched = true;
        foreach (['spots', 'place_fact_observations', 'place_fact_corrections'] as $table) {
            DB::statement('ANALYZE '.$table);
        }
        $stage = 'deployed_baseline';
        $selectController(false);
        $measure('deployed_baseline', $originalController);
        $keyId = $report['variants']['deployed_baseline']['food_first']['public_place_ids'][0];
        $measureNarrow('deployed_baseline', $originalFacts, $originalController, $keyId);
        $stage = 'native_import';
        applyPreparedPlaces($records, $beforeRows, $manifest['records_sha256']);
        foreach (['spots', 'place_fact_observations', 'place_fact_corrections'] as $table) {
            DB::statement('ANALYZE '.$table);
        }
        $stage = 'expanded_candidate';
        $selectController(true);
        $measure('expanded_candidate', $candidateController);
        $measureNarrow('expanded_candidate', $candidateFacts, $candidateController, $keyId);
        $stage = 'expanded_policy_identity_parity';
        $originalAllowed = $originalFacts->publiclyRecommendable(Spot::query())->orderBy('spots.id')->pluck('spots.id')->all();
        $candidateAllowed = $candidateFacts->publiclyRecommendable(Spot::query())->orderBy('spots.id')->pluck('spots.id')->all();
        stagingPackCheck($originalAllowed === $candidateAllowed, 'Expanded access identity sets differ');
        $report['all_expanded_access_ids_identical'] = true;
        $report['access_ids_compared'] = count($candidateAllowed);
        $stage = 'original_candidate_api_parity';
        $parityCases = $cases + ['detail' => '/api/places/'.$keyId, 'court' => '/api/places?category=court', 'pitch' => '/api/places?category=pitch', 'park' => '/api/places?category=park', 'playground' => '/api/places?category=playground', 'tennis_activity' => '/api/places?activity=tennis', 'cafe_activity' => '/api/places?activity=cafe'];
        foreach ($parityCases as $name => $uri) {
            $selectController(false);
            $original = stagingPackApi($uri, $user);
            stagingPackCheck(app('router')->current()->getController() === $originalController, 'Original parity used wrong controller');
            $selectController(true);
            $candidate = stagingPackApi($uri, $user);
            stagingPackCheck(app('router')->current()->getController() === $candidateController, 'Candidate parity used wrong controller');
            stagingPackCheck($original === $candidate, 'Candidate changed API payload: '.$name);
            $report['api_parity'][$name] = ['identical' => true, 'total' => $candidate['meta']['total'] ?? null, 'payload_sha256' => hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR))];
            $write();
        }
        foreach (array_keys($cases) as $name) {
            $ratio = $report['variants']['expanded_candidate'][$name]['p95_ms'] / $report['variants']['deployed_baseline'][$name]['p95_ms'];
            $report['p95_ratio_to_deployed_baseline'][$name] = $ratio;
            $report['more_than_20_percent_slower'][$name] = $ratio > 1.2;
        }
    } finally {
        DB::rollBack();
    }
    $stage = 'rollback_verification';
    foreach ($tables as $table) {
        $after[$table] = stagingPackDigest($table);
    }
    stagingPackCheck($before === $after, 'Candidate rehearsal rollback differed');
    $report += ['status' => 'passed', 'rollback_exact' => true, 'before_table_digests' => $before, 'after_table_digests' => $after];
} catch (Throwable $error) {
    file_put_contents($input.'/batch-v2-candidate-performance-failure-private.txt', $error::class.': '.$error->getMessage());
    $report += ['status' => 'failed', 'failed_stage' => $stage, 'error_details_exported' => false];
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $selectController(false);
    Carbon\Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    if ($statisticsTouched) {
        try {
            DB::statement('SET statement_timeout = 120000');
            DB::statement('SET lock_timeout = 5000');
            foreach (['spots', 'place_fact_observations', 'place_fact_corrections'] as $table) {
                DB::statement('ANALYZE '.$table);
            }
            $report['baseline_statistics_refreshed_after_rollback'] = true;
        } catch (Throwable $cleanupError) {
            file_put_contents($input.'/batch-v2-candidate-cleanup-failure-private.txt', $cleanupError::class.': '.$cleanupError->getMessage());
            $report['status'] = 'failed';
            $report['failed_stage'] = 'baseline_statistics_cleanup';
        }
    }
}
$report['completed_at'] = gmdate('c');
$report['note'] = 'Compare the expanded candidate against the current deployed baseline. These are sequential samples. Sequence and analysis counters can advance. A harness pass is separate from the p95 gate and does not establish deployment or full-programme completion.';
file_put_contents($input.'/batch-v2-candidate-performance-result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['status'] === 'passed' ? 0 : 1);
