<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Enums\LocationSource;
use App\Enums\TransportMode;
use App\Models\PlaceFactObservation;
use App\Models\Spot;
use App\Models\User;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(1);
});
require __DIR__.'/apply-pack.php';
function demandPack(bool $ok, string $reason): void
{
    if (! $ok) {
        throw new RuntimeException($reason);
    }
}
function rowsFor(string $table): array
{
    return array_map(static fn (object $row): array => json_decode($row->raw, true, flags: JSON_THROW_ON_ERROR), DB::select('SELECT row_to_json(t)::text AS raw FROM '.$table.' t ORDER BY id'));
}
function tableDigest(string $table): string
{
    return hash('sha256', json_encode(rowsFor($table), JSON_THROW_ON_ERROR));
}
function packApi(string $uri, User $user): array
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_HOST' => config('app.app_domain'), 'HTTPS' => 'on']);
    $request->setUserResolver(static fn () => $user);
    $response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    demandPack($response->getStatusCode() === 200, 'API failed: '.$uri.' status '.$response->getStatusCode().' '.substr($response->getContent(), 0, 150));

    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
}

demandPack(app()->environment('testing') && DB::selectOne('SELECT current_database() AS name')->name === 'exp72_ready_20260928', 'Only the dedicated local rehearsal database is allowed.');
demandPack(DB::table('users')->count() === 0, 'Rehearsal must contain no imported user data.');
config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array']);
DB::statement('SET statement_timeout = 120000');
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
$root = getenv('PLACES_PACK_ROOT');
$output = __DIR__.'/2026-09-28';
$baselinePath = $root.'/storage/app/private/places-research/production-pack-2026-09-28/places-only.json';
$baseline = json_decode(file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents($output.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
demandPack(hash_file('sha256', $baselinePath) === $manifest['baseline_sha256'], 'Baseline file changed.');
demandPack(hash_file('sha256', $output.'/records.jsonl') === $manifest['records_sha256'], 'Pack checksum mismatch.');
$records = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($output.'/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
demandPack(count($records) === $manifest['records'] && count(array_unique(array_column($records, 'key'))) === count($records), 'Unexpected or duplicate pack identities.');
$before = rowsFor('spots');
$beforeById = array_column($before, null, 'id');
$baselineById = array_column($baseline['tables']['spots'], null, 'id');
demandPack(preparedPlaceRowFingerprint($beforeById) === preparedPlaceRowFingerprint($baselineById), 'Local baseline does not match the frozen staging export.');
$protected = [];
foreach (['spots', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_reconciliations', 'place_destination_reviews', 'media_assets', 'media_attachments', 'users', 'reviews', 'spot_feedback', 'spot_checkins'] as $table) {
    if (Schema::hasTable($table)) {
        $protected[$table] = tableDigest($table);
    }
}
$beforeObservationCount = PlaceFactObservation::count();
$report = ['verified_at' => gmdate('c'), 'application_commit' => trim((string) shell_exec('git rev-parse HEAD')), 'environment' => 'isolated local testing', 'database' => 'exp72_ready_20260928', 'production_changed' => false, 'staging_changed' => false, 'input_records_sha256' => $manifest['records_sha256'], 'baseline_sha256' => $manifest['baseline_sha256']];
$report['verification_code_sha256'] = [];
foreach (['apply-pack.php', 'rehearse.php', 'prepare.py'] as $file) {
    $report['verification_code_sha256'][$file] = hash_file('sha256', __DIR__.'/'.$file);
}
$report['before_api'] = [];
$beforeApi = is_file($output.'/before-api.json') ? json_decode(file_get_contents($output.'/before-api.json'), true, flags: JSON_THROW_ON_ERROR) : null;
$controllerHash = $beforeApi['controller_sha256'] ?? $beforeApi['api_controller_sha256'] ?? null;
if ($beforeApi !== null && $beforeApi['baseline_sha256'] === $manifest['baseline_sha256'] && $controllerHash === hash_file('sha256', getcwd().'/app/Http/Controllers/Api/PlacesController.php')) {
    $report['before_api'] = $beforeApi['results'];
    $report['before_api_evidence'] = 'Reused measured HTTP responses for the identical baseline and API controller from before-api.json';
} else {
    foreach (['food_drink', 'culture', 'park', 'pitch', 'court', 'playground', 'dog_park', 'swimming'] as $coarse) {
        $body = packApi('/api/places?category='.$coarse, $user);
        $report['before_api'][$coarse] = $body['meta']['total'];
        echo json_encode(['before_api' => $coarse, 'total' => $body['meta']['total']]).PHP_EOL;
    }
}
DB::beginTransaction();
try {
    DB::statement('SET LOCAL statement_timeout = 120000');
    $mapping = applyPreparedPlaces($records, $baselineById, $manifest['records_sha256']);
    $selectedIds = array_column($mapping, 'id');
    $afterById = array_column(rowsFor('spots'), null, 'id');
    $selectedExisting = array_filter(array_column($records, 'existing_id'));
    foreach ($beforeById as $id => $row) {
        if (! in_array($id, $selectedExisting, true)) {
            demandPack(preparedPlaceRowFingerprint($afterById[$id]) === preparedPlaceRowFingerprint($row), 'An unrelated existing spot changed: '.$id);
        }
        foreach (['parent_spot_id', 'canonical_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id', 'destination_reviewed_at', 'destination_grouping_evidence', 'photo_url', 'photo_attribution'] as $key) {
            demandPack(($afterById[$id][$key] ?? null) === ($row[$key] ?? null), 'Protected identity, grouping or media changed.');
        }
    }
    foreach ($protected as $table => $digest) {
        if (! in_array($table, ['spots', 'place_fact_observations', 'place_fact_revisions'], true)) {
            demandPack(tableDigest($table) === $digest, 'Protected table changed: '.$table);
        }
    }
    $recordById = [];
    foreach ($records as $record) {
        $recordById[$mapping[$record['key']]['id']] = $record;
    }
    $factChecks = 0;
    $eligibilityIds = app(DestinationGrouping::class)->eligible(Spot::query())->whereIn('spots.id', $selectedIds)->pluck('spots.id')->all();
    foreach (array_chunk($selectedIds, 150) as $chunk) {
        $spots = Spot::query()->whereIn('id', $chunk)->get();
        $resolved = app(PlaceFacts::class)->resolveMany($spots);
        foreach ($spots as $spot) {
            $r = $recordById[$spot->id];
            $f = $resolved[$spot->id];
            demandPack($spot->source === $r['source'] && $spot->source_id === $r['source_id'], 'Source identity changed.');
            demandPack(abs($f['location']['map_point']['lat'] - $r['lat']) < 0.000001 && abs($f['location']['map_point']['lng'] - $r['lng']) < 0.000001, 'Resolved coordinate mismatch.');
            demandPack(preparedPlaceRowFingerprint(['tags' => $spot->tags]) === preparedPlaceRowFingerprint(['tags' => $r['tags'] ?: null]), 'Practical source tags were lost.');
            demandPack(! in_array($f['access']['value'], ['private', 'no', 'customers', 'members', 'permit'], true) && $f['access']['status'] !== 'conflicting', 'Restricted facts entered ready batch.');
            if ($r['observation']['fee']['raw'] === null) {
                demandPack($f['fee']['value'] === 'unknown', 'Missing fee became free or paid.');
            }
            demandPack($f['name_kind'] === $r['name_kind'], 'Source and descriptive names were mixed.');
            $factChecks++;
        }
    }
    $day = CarbonImmutable::parse('2026-09-29 09:00:00', 'Europe/Berlin');
    $free = new Constraints($day, $day->addHours(12), budget: 'free');
    $composerCount = 0;
    $freeCount = 0;
    foreach (array_chunk($eligibilityIds, 100) as $chunk) {
        $candidates = app(CandidateRepository::class)->byIds(array_map(static fn (int $id): string => 'spot:'.$id, $chunk), $day);
        demandPack(count($candidates) === count($chunk), 'Composer lost eligible identities.');
        foreach ($candidates as $candidate) {
            $r = $recordById[(int) substr($candidate->id, 5)];
            demandPack($candidate->category === $r['category'], 'Composer category mismatch.');
            if ($r['observation']['fee']['raw'] === null && ($afterById[(int) substr($candidate->id, 5)]['price_range'] ?? null) === null) {
                demandPack($candidate->costTier === 'unknown', 'Unknown cost promoted to free.');
            }
        }
        foreach (app(FeasibilityFilter::class)->filter($free, $candidates) as $candidate) {
            demandPack($candidate->costTier === 'free', 'Strict free filter admitted an unknown/paid place.');
            $freeCount++;
        }
        $composerCount += count($candidates);
    }
    $report['after_api'] = [];
    DB::statement('ANALYZE spots');
    DB::statement('ANALYZE place_fact_observations');
    foreach (['food_drink', 'culture', 'park', 'pitch', 'court', 'playground', 'dog_park', 'swimming'] as $coarse) {
        $body = packApi('/api/places?category='.$coarse, $user);
        $report['after_api'][$coarse] = $body['meta']['total'];
        echo json_encode(['after_api' => $coarse, 'total' => $body['meta']['total']]).PHP_EOL;
    }
    $report['detail_api_samples'] = [];
    $samples = [];
    foreach ($records as $record) {
        if ($record['existing_id'] === null && $record['role'] === 'destination') {
            $samples[$record['source'].':'.$record['category']] ??= $mapping[$record['key']]['id'];
        }
    }
    foreach ($samples as $kind => $id) {
        $body = packApi('/api/places/'.$id, $user);
        demandPack($body['data']['id'] === $id, 'Detail API identity mismatch.');
        $report['detail_api_samples'][$kind] = $id;
    }
    $firstSpots = tableDigest('spots');
    $firstObservations = tableDigest('place_fact_observations');
    $firstRevision = tableDigest('place_fact_revisions');
    // Rebuild exact preconditions from the first successful application. Source
    // IDs and ingestion keys remain identical; a second pass must be a no-op.
    $replayRecords = $records;
    foreach ($replayRecords as &$record) {
        $record['existing_id'] = $mapping[$record['key']]['id'];
    }
    unset($record);
    applyPreparedPlaces($replayRecords, $afterById, $manifest['records_sha256']);
    $replayStable = ['spots' => tableDigest('spots') === $firstSpots, 'place_fact_observations' => tableDigest('place_fact_observations') === $firstObservations, 'place_fact_revisions' => tableDigest('place_fact_revisions') === $firstRevision];
    demandPack(! in_array(false, $replayStable, true), 'Replay changed tables: '.json_encode($replayStable, JSON_THROW_ON_ERROR));
    $report += ['records_verified' => $factChecks, 'created' => count($afterById) - count($beforeById), 'refreshed' => count($selectedExisting), 'native_composer_identities_verified' => $composerCount, 'supporting_records_not_recommended' => count($records) - $composerCount, 'explicit_free_candidates_in_test_window' => $freeCount, 'observations_added' => PlaceFactObservation::count() - $beforeObservationCount, 'replay_exact_no_op' => true, 'unrelated_spots_unchanged' => true, 'protected_tables_unchanged' => true];
    $report['by_neighbourhood'] = array_count_values(array_column($mapping, 'veedel'));
    ksort($report['by_neighbourhood']);
} finally {
    DB::rollBack();
}
foreach ($protected as $table => $digest) {
    demandPack(tableDigest($table) === $digest, 'Rollback did not restore '.$table);
}
$report['exact_rollback_verified'] = true;
foreach ($report['verification_code_sha256'] as $file => $digest) {
    demandPack(hash_file('sha256', __DIR__.'/'.$file) === $digest, 'Verification code changed while running: '.$file);
}
$report['users_imported'] = DB::table('users')->count();
$report['full_composer_request_tested'] = false;
$report['known_application_limits'] = ['Unnamed facilities remain supporting data until explicit-activity retrieval is implemented.', 'Controller budget relaxation is outside this data rehearsal; no full free-football workflow is claimed.'];
file_put_contents($output.'/rehearsal.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
