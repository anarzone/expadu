<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Enums\LocationSource;
use App\Enums\TransportMode;
use App\Models\Spot;
use App\Models\User;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\PlaceIdentity;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use Carbon\CarbonImmutable;
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
if ($argc !== 2 || ! in_array($argv[1], ['--local', '--staging'], true)) {
    fwrite(STDERR, "Choose exactly --local or --staging. This program always rolls back.\n");
    exit(1);
}
$isStaging = $argv[1] === '--staging';
$input = getenv('PLACES_REHEARSAL_INPUT');
if (! is_string($input) || ! is_dir($input)) {
    throw new RuntimeException('An explicit prepared input directory is required.');
}
umask(0077);
$privateExceptionHandler = static function (Throwable $error) use ($input): never {
    file_put_contents($input.'/failure-private.txt', $error::class.': '.$error->getMessage().PHP_EOL);
    fwrite(STDERR, "Rehearsal failed during preflight; details stay in the evidence directory.\n");
    exit(1);
};
set_exception_handler($privateExceptionHandler);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$privateConfiguration = [
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
config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array', 'services.composer_llm.enabled' => false]);
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
$tables = array_keys(array_fill_keys(['spots', 'veedels', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_destination_reviews', 'place_reconciliations', 'media_assets', 'media_attachments'], true));
$report = ['checked_at' => gmdate('c'), 'environment' => $isStaging ? 'staging' : 'isolated_local', 'mode' => 'transaction_rehearsal_only', 'input_checksums' => $checksums, 'verification_code_sha256' => hash_file('sha256', __FILE__), 'raw_records_exported' => false, 'production_changed' => false, 'catalogue_commit_attempted' => false, 'existing_user_accounts_or_plans_loaded' => false, 'feedback_lookup_scope' => 'non-persisted synthetic user ID only', 'synthetic_user_persisted' => false, 'activity_qualifications_applied' => 0];
$stage = 'preflight';
try {
    DB::beginTransaction();
    DB::statement('SET LOCAL statement_timeout = 120000');
    DB::statement('SET LOCAL lock_timeout = 5000');
    DB::statement('LOCK TABLE '.implode(', ', $tables).' IN SHARE ROW EXCLUSIVE MODE');
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = stagingPackDigest($table);
    }
    $beforeRows = array_column(stagingPackRows('spots'), null, 'id');
    $beforeObservations = array_column(stagingPackRows('place_fact_observations'), null, 'id');
    $grouping = app(DestinationGrouping::class);
    $generalBefore = $grouping->general(Spot::query())->pluck('spots.id')->all();
    $activityBefore = $grouping->eligible(Spot::query(), includeActivityFacilities: true)->pluck('spots.id')->all();
    $sources = [];
    foreach ($beforeRows as $row) {
        $sources[$row['source'].':'.$row['source_id']][] = $row['id'];
    }
    foreach ($records as $record) {
        $id = $record['existing_id'];
        stagingPackCheck(isset($expected[$record['key']]) && $expected[$record['key']]['id'] === $id, 'Unexpected expected identity.');
        if ($id === null) {
            stagingPackCheck(! isset($sources[$record['key']]), 'New public source identity already exists: '.$record['key']);
        } else {
            stagingPackCheck(isset($beforeRows[$id]) && preparedPlaceRowFingerprint($beforeRows[$id]) === $expected[$record['key']]['sha256'], 'Existing public place fingerprint changed: '.$id);
        }
    }
    $report['preflight_records'] = count($records);
    DB::beginTransaction();
    try {
        $stage = 'native_import';
        $mapping = applyPreparedPlaces($records, $beforeRows, $manifest['records_sha256']);
        $ids = array_column($mapping, 'id');
        $afterRows = array_column(stagingPackRows('spots'), null, 'id');
        $selectedExisting = array_fill_keys(array_filter(array_column($records, 'existing_id')), true);
        $protectedColumns = ['parent_spot_id', 'canonical_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id', 'destination_reviewed_at', 'destination_grouping_evidence', 'photo_url', 'photo_attribution'];
        foreach ($beforeRows as $id => $row) {
            stagingPackCheck(isset($afterRows[$id]), 'Existing public place ID was removed.');
            if (! isset($selectedExisting[$id])) {
                stagingPackCheck(preparedPlaceRowFingerprint($row) === preparedPlaceRowFingerprint($afterRows[$id]), 'Unrelated public place changed: '.$id);
            }
            foreach ($protectedColumns as $column) {
                stagingPackCheck(($row[$column] ?? null) === ($afterRows[$id][$column] ?? null), 'Existing identity, grouping or media changed: '.$id);
            }
        }
        $stage = 'shared_facts';
        $generalAfter = $grouping->general(Spot::query())->pluck('spots.id')->all();
        $activityAfter = $grouping->eligible(Spot::query(), includeActivityFacilities: true)->pluck('spots.id')->all();
        $report['general_visibility_losses'] = array_values(array_diff($generalBefore, $generalAfter));
        $report['activity_visibility_losses'] = array_values(array_diff($activityBefore, $activityAfter));
        stagingPackCheck($report['general_visibility_losses'] === [] && $report['activity_visibility_losses'] === [], 'Existing place visibility loss requires a separate review.');
        $expectedCandidates = array_values(array_intersect($ids, $activityAfter));
        sort($expectedCandidates);
        $actualCandidates = [];
        $day = CarbonImmutable::parse('2026-09-29 12:00', 'Europe/Berlin');
        $free = new Constraints($day, $day->addHours(7), budget: 'free');
        $football = new Constraints($day, $day->addHours(7), budget: 'free', activities: ['soccer']);
        $factChecks = $candidateChecks = $freeCount = $footballCount = 0;
        $samples = [];
        $recordById = [];
        foreach ($records as $record) {
            $recordById[$mapping[$record['key']]['id']] = $record;
        }
        foreach (array_chunk($ids, 150) as $chunk) {
            $spots = Spot::whereIn('id', $chunk)->get();
            $facts = app(PlaceFacts::class)->resolveMany($spots);
            foreach ($spots as $spot) {
                $f = $facts[$spot->id];
                $record = $recordById[$spot->id];
                stagingPackCheck(is_string($f['name']['value']) && trim($f['name']['value']) !== '' && $f['location']['map_point']['status'] === 'known', 'Missing consumer name or coordinates: '.$spot->id);
                stagingPackCheck(isset($f['practical'], $f['activities'], $f['fee']['status'], $f['hours']['status']), 'Missing shared facts: '.$spot->id);
                stagingPackCheck($spot->source === $record['source'] && $spot->source_id === $record['source_id'], 'Source identity mismatch: '.$spot->id);
                stagingPackCheck(preparedPlaceRowFingerprint(['tags' => $spot->tags]) === preparedPlaceRowFingerprint(['tags' => $record['tags'] ?: null]), 'Practical source tags changed: '.$spot->id);
                stagingPackCheck(abs($f['location']['map_point']['lat'] - $record['lat']) < 0.000001 && abs($f['location']['map_point']['lng'] - $record['lng']) < 0.000001, 'Resolved coordinate mismatch requires review: '.$spot->id);
                if ($record['observation']['fee']['raw'] === null && $f['fee']['reviewed_at'] === null) {
                    stagingPackCheck($f['fee']['value'] === 'unknown', 'Missing source fee became a price claim: '.$spot->id);
                }
                $samples[$spot->source.':'.$spot->category->value] ??= $spot->id;
                $factChecks++;
            }
            $candidates = app(CandidateRepository::class)->byIds(array_map(static fn ($id) => 'spot:'.$id, $chunk), $day);
            foreach ($candidates as $candidate) {
                $id = (int) substr($candidate->id, 5);
                $actualCandidates[] = $id;
                stagingPackCheck($candidate->placeFacts === $facts[$id], 'Composer and Places facts differ: '.$id);
                $freeCount += (int) app(FeasibilityFilter::class)->matchesDiscovery($free, $candidate);
                $footballCount += (int) app(FeasibilityFilter::class)->matchesDiscovery($football, $candidate);
                $candidateChecks++;
            }
        }
        sort($actualCandidates);
        stagingPackCheck($factChecks === count($records), 'Not every prepared identity received shared-fact verification.');
        stagingPackCheck($actualCandidates === $expectedCandidates, 'Composer retrieval lost or duplicated eligible identities.');
        $stage = 'places_api';
        $api = [];
        foreach (['food_drink', 'culture', 'park', 'pitch', 'court', 'playground', 'dog_park', 'swimming'] as $category) {
            $body = stagingPackApi('/api/places?category='.$category, $user);
            $api[$category] = $body['meta']['total'];
        }
        foreach ($samples as $id) {
            $body = stagingPackApi('/api/places/'.$id, $user);
            stagingPackCheck($body['data']['id'] === $id, 'Detail API identity changed.');
            $expectedFacts = app(PlaceFacts::class)->resolve(Spot::findOrFail($id));
            foreach ($body['data']['place_facts'] as $key => $value) {
                stagingPackCheck($value === $expectedFacts[$key], 'Detail API shared facts differ: '.$key);
            }
            $plan = ['slots' => [['id' => 'spot:'.$id]], 'pins' => ['spot:'.$id], 'locked' => ['spot:'.$id]];
            stagingPackCheck(app(PlaceIdentity::class)->normalizePlan($plan) === $plan, 'Synthetic saved reference changed.');
        }
        $stage = 'history_and_replay';
        $afterObservations = array_column(stagingPackRows('place_fact_observations'), null, 'id');
        foreach ($beforeObservations as $id => $row) {
            stagingPackCheck(isset($afterObservations[$id]) && preparedPlaceRowFingerprint($afterObservations[$id]) === preparedPlaceRowFingerprint($row), 'Source history changed.');
        }
        $after = [];
        foreach ($tables as $table) {
            $after[$table] = stagingPackDigest($table);
            if (! in_array($table, ['spots', 'place_fact_observations', 'place_fact_revisions'], true)) {
                stagingPackCheck($after[$table] === $before[$table], 'Protected table changed: '.$table);
            }
        }
        $replay = $records;
        foreach ($replay as &$record) {
            $record['existing_id'] = $mapping[$record['key']]['id'];
        }
        unset($record);
        applyPreparedPlaces($replay, $afterRows, $manifest['records_sha256']);
        foreach ($tables as $table) {
            stagingPackCheck(stagingPackDigest($table) === $after[$table], 'Repeat import changed: '.$table);
        }
        $report += ['prepared_records_verified' => $factChecks, 'created_in_transaction' => count($afterRows) - count($beforeRows), 'refreshed_in_transaction' => count($selectedExisting), 'composer_identities' => $candidateChecks, 'not_composer_eligible' => count($records) - $candidateChecks, 'explicit_free_candidates' => $freeCount, 'verified_free_public_football' => $footballCount, 'places_api_totals' => $api, 'detail_api_samples' => count($samples), 'checks' => ['all_existing_ids_preserved' => true, 'identity_grouping_media_unchanged' => true, 'unrelated_places_unchanged' => true, 'source_history_preserved' => true, 'shared_facts_equal' => true, 'synthetic_saved_references_preserved' => true, 'replay_exact' => true], 'before_table_digests' => $before];
    } finally {
        DB::rollBack();
    }
    $stage = 'rollback_verification';
    foreach ($tables as $table) {
        stagingPackCheck(stagingPackDigest($table) === $before[$table], 'Rollback did not restore: '.$table);
    }
    $report['checks']['rollback_exact'] = true;
    stagingPackCheck(hash_file('sha256', __FILE__) === $report['verification_code_sha256'], 'Verification code changed while running.');
    $report['status'] = 'passed';
    $report['sequence_note'] = 'PostgreSQL sequence allocations are not transactional; no sequence is reset. Table contents are restored exactly.';
} catch (Throwable $error) {
    // Error details remain in the local/server evidence directory, never on stdout.
    file_put_contents($input.'/failure-private.txt', $error::class.': '.$error->getMessage().PHP_EOL);
    $report += ['status' => 'failed', 'failed_stage' => $stage, 'error_details_exported' => false];
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
file_put_contents($input.'/rehearsal-result.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['status'] === 'passed' ? 0 : 1);
