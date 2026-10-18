<?php

use App\Composer\CandidateRepository;
use App\Enums\LocationSource;
use App\Enums\TransportMode;
use App\Models\Spot;
use App\Models\User;
use App\Places\DestinationGrouping;
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
use Illuminate\Support\Facades\Schema;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

// Default rehearsal rolls back. Commit modes require separate explicit operator approval.
if ($argc < 2 || $argc > 3 || $argv[1] !== '--staging' || ! in_array($argv[2] ?? '--rehearse', ['--rehearse', '--apply-approved', '--recover-approved'], true)) {
    fwrite(STDERR, "Choose --staging and an optional mode. Default rehearsal always rolls back.\n");
    exit(1);
}
$isStaging = $argv[1] === '--staging';
$input = getenv('PLACES_REHEARSAL_INPUT');
if (! is_string($input) || ! is_dir($input)) {
    throw new RuntimeException('An explicit prepared input directory is required.');
}
umask(0077);
$privateExceptionHandler = static function (Throwable $error) use ($input): never {
    file_put_contents($input.'/canary-failure-private.txt', $error::class.': '.$error->getMessage().PHP_EOL);
    fwrite(STDERR, "Canary operation failed; details stay in the evidence directory.\n");
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

$expectedCode = json_decode('{"app/Places/PlaceFacts.php":"0aac4a26a8160219056d42216b6d2df30cc30d79be9aac8161c0c29262091c2c","app/Places/DestinationGrouping.php":"b7b4f543f5d7092b608517c342f7527b81d95a58ec7b4f8038f0a0048f631f5b","app/Http/Controllers/Api/PlacesController.php":"c49a235039265a0aa6067ea5732a8bf2658dad09d66140ff7f3ea98c79659b90","app/Places/RecordPlaceObservation.php":"f07268332199115932aafd062783fb011e7adde377037b0f492af99a2c84a1b4","app/Places/PlaceIdentity.php":"8563c41b2a9c495e857b1a108dcad84c110be0bed3ecffdb05eb4ca023b95a57","app/Models/Spot.php":"dffa46ffd5b794eeebfe93428615b864f6d7c897438bca917c574e6916b8af00","app/Http/Resources/PlaceResource.php":"7537688639bbe10e453a0ce09134dcc3b2a81a429abd1ec20a70889b80688355","app/Composer/CandidateRepository.php":"12863a896800e959cf3375593432eea413e8b5b70787a5c2844ba488fb73bcdb"}', true, flags: JSON_THROW_ON_ERROR);
$expectedHelpers = json_decode('{"GuardedCanary.php":"b1aa794c89975ca32a8228892616d6819dcba870a999462b1535fcc67a9eaef3","CanaryJournal.php":"b84744047209885ba994e5a151882d0dc2c7ebc127999edaab4cd4f1c39cbf13"}', true, flags: JSON_THROW_ON_ERROR);
foreach ($expectedCode as $path => $hash) {
    stagingPackCheck(hash_file('sha256', getcwd().'/'.$path) === $hash, 'Running application differs from reviewed code');
}
foreach ($expectedHelpers as $path => $hash) {
    stagingPackCheck(hash_file('sha256', __DIR__.'/'.$path) === $hash, 'Recovery implementation differs from reviewed code');
}
$reviewedInputs = [
    'canary-acceptance.json' => 'f5278ac5e7833c9e6b7adcd3d8fbca25b1edcaf18287cef220bb1009a0e269e6',
    'records.jsonl' => 'ecfc2777acf6e91d8e574394418120163e725d2b432201179fbcc60fd20f605a',
    'manifest.json' => 'fb9a648002cc6130d38b9f658da6558cff609caa5b511bdcdf996a58566e1ce9',
    'apply-pack.php' => 'cd2fc73545d2255d64a6ac80f590df5fb54c9db458022bfc36444af47e8b5a66',
    'fingerprints.json' => 'e63adc8463317a11a74b46653e1e1d63513fd79eb38e089ac02713edb8e89f2f',
    'canary-manifest-v2.json' => 'badef6eb0727c6af779934742acba976b483fd98bdd9f1dfffcddae70ee33cf3',
];
foreach ($reviewedInputs as $path => $hash) {
    stagingPackCheck(hash_file('sha256', $input.'/'.$path) === $hash, 'Release input differs from reviewed file');
}
$mode = $argv[2] ?? '--rehearse';
$runnerHash = hash_file('sha256', __FILE__);
$operation = '6f1e5fe0-0b54-4d7b-81eb-29d000001001';
$manifestHash = $reviewedInputs['canary-manifest-v2.json'];
if ($mode !== '--rehearse') {
    // Operator acknowledgement is additional to the user's external authorization.
    stagingPackCheck(getenv('PLACES_CANARY_COMMIT_APPROVAL') === $mode.':'.$operation.':'.$manifestHash.':'.$runnerHash, 'Explicit reviewed operation approval is required before commit');
}
require $input.'/apply-pack.php';
require __DIR__.'/CanaryJournal.php';
$helper = new GuardedCanary;
$journal = new CanaryJournal;
$records = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($input.'/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$all = array_column($records, null, 'key');
$manifest = json_decode(file_get_contents($input.'/canary-manifest-v2.json'), true, flags: JSON_THROW_ON_ERROR);
$acceptance = json_decode(file_get_contents($input.'/canary-acceptance.json'), true, flags: JSON_THROW_ON_ERROR);
stagingPackCheck($acceptance['manifest_sha256'] === $manifestHash && count($acceptance['entries']) === 100, 'Unexpected frozen acceptance set');
$expected = json_decode(file_get_contents($input.'/fingerprints.json'), true, flags: JSON_THROW_ON_ERROR);
$selected = [];
foreach ($manifest['entries'] as $entry) {
    $record = $all[$entry['key']] ?? null;
    stagingPackCheck($record !== null && $record['record_sha256'] === $entry['record_sha256'] && $record['existing_id'] === $entry['existing_id'], 'Selected source record changed');
    $selected[] = $record;
}
stagingPackCheck(count($records) === 4643 && count($all) === 4643 && count($selected) === 100 && count(array_unique(array_column($selected, 'key'))) === 100, 'Unexpected package size or identity duplication');
stagingPackCheck(count(array_filter($selected, fn ($r) => $r['existing_id'] === null)) === 50 && count(array_unique(array_column($selected, 'category'))) === 22, 'Unexpected canary shape');
$context = ['schema_version' => 1, 'runner_sha256' => $runnerHash, 'database' => 'expadu_staging', 'package_sha256' => $reviewedInputs['records.jsonl'], 'manifest_sha256' => $manifestHash, 'acceptance_sha256' => $reviewedInputs['canary-acceptance.json'], 'application_sha256' => $helper->hash($expectedCode), 'importer_sha256' => $reviewedInputs['apply-pack.php'], 'recovery_sha256' => $helper->hash($expectedHelpers), 'record_set_sha256' => $helper->hash($manifest['entries'])];
$actor = 'expadu-staging-canary-20260929';
$before = [];
$out = ['started_at' => gmdate('c'), 'scope' => 'Exact reviewed 100-record staging canary', 'mode' => $mode, 'operation_id' => $operation, 'context' => $context, 'raw_rows_exported' => false, 'catalogue_commit_attempted' => $mode !== '--rehearse'];
DB::beginTransaction();
try {
    DB::statement('SET LOCAL lock_timeout = 10000');
    DB::statement('SET LOCAL statement_timeout = 60000');
    DB::statement('LOCK TABLE spots, veedels, place_fact_observations, place_fact_corrections, place_fact_revisions, place_destination_reviews, place_reconciliations IN SHARE ROW EXCLUSIVE MODE');
    $journalExists = Schema::hasTable('place_catalogue_operations');
    if (! $journalExists) {
        stagingPackCheck($mode === '--rehearse', 'The operation-journal migration must be deployed before commit');
        DB::statement('CREATE TEMPORARY TABLE place_catalogue_operations (id uuid PRIMARY KEY, state text NOT NULL, context jsonb NOT NULL, receipt text NOT NULL, recovery text, actor varchar(191) NOT NULL, created_at timestamp with time zone NOT NULL, updated_at timestamp with time zone NOT NULL) ON COMMIT DROP');
    }
    foreach (['spots', 'veedels', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_destination_reviews', 'place_reconciliations'] as $table) {
        $rows = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $before[$table] = $helper->hash($rows);
    }
    $visibility = static function (): array {
        $grouping = app(DestinationGrouping::class);

        return ['general' => $grouping->general(Spot::query())->orderBy('id')->pluck('id')->all(), 'activity' => $grouping->eligible(Spot::query(), true)->orderBy('id')->pluck('id')->all()];
    };
    $visibleBefore = $visibility();
    // Keep the outer locks while checking rollback parity.
    DB::beginTransaction();
    if ($mode === '--recover-approved') {
        $recovered = $journal->recover($operation, $context, $actor, 'Withdraw the explicitly approved staging canary using its unchanged operation journal.');
        $out['recovery'] = ['restored_streams' => $recovered['restored_source_streams'], 'withdrawn_additions' => $recovered['withdrawn_additions'], 'retained_alias_history_changes' => count($recovered['alias_deltas'])];
    } else {
        $baseline = [];
        foreach (DB::select('SELECT row_to_json(s)::text AS raw FROM spots s ORDER BY id') as $row) {
            $decoded = json_decode($row->raw, true, flags: JSON_THROW_ON_ERROR);
            $baseline[$decoded['id']] = $decoded;
        }
        $isReplay = DB::table('place_catalogue_operations')->where('id', $operation)->exists();
        if (! $isReplay) {
            stagingPackCheck(count($baseline) === 9928, 'Stored catalogue changed since reviewed staging baseline');
            foreach ($selected as $record) {
                foreach (['general', 'activity'] as $kind) {
                    stagingPackCheck(in_array($record['existing_id'], $visibleBefore[$kind], true) === $acceptance['entries'][$record['key']]['before_'.$kind], 'Baseline visibility differs from frozen expectations');
                }
                if ($record['existing_id'] !== null) {
                    stagingPackCheck(isset($baseline[$record['existing_id']]) && preparedPlaceRowFingerprint($baseline[$record['existing_id']]) === $expected[$record['key']]['sha256'], 'Existing identity changed since reviewed comparison');
                }
            }
        }
        $receipt = $journal->apply($operation, $context, $selected, $baseline, $actor);
        $out['applied_records'] = count($receipt['mapping']);
        $out['stored_spots_inside_transaction'] = DB::table('spots')->count();
        $ids = array_column($receipt['mapping'], 'id');
        $visibleAfter = $visibility();
        $expectedComposer = [];
        foreach (['general', 'activity'] as $kind) {
            stagingPackCheck(array_values(array_diff($visibleBefore[$kind], $ids)) === array_values(array_diff($visibleAfter[$kind], $ids)), 'Import changed unselected catalogue visibility');
            $out[$kind.'_visible_canary'] = count(array_intersect($ids, $visibleAfter[$kind]));
        }
        $out['unselected_visibility_unchanged'] = true;
        $out['detail_api_checks'] = 0;
        $byId = [];
        foreach ($receipt['mapping'] as $key => $mapping) {
            $id = $mapping['id'];
            $r = $all[$key];
            $byId[$id] = $r;
            foreach (['general', 'activity'] as $kind) {
                stagingPackCheck(in_array($id, $visibleAfter[$kind], true) === $acceptance['entries'][$key]['after_'.$kind], 'Imported visibility differs from frozen expectations');
            }
            if ($acceptance['entries'][$key]['composer']) {
                $expectedComposer[] = 'spot:'.$id;
            }
            $spot = Spot::findOrFail($id);
            $facts = app(PlaceFacts::class)->resolve($spot);
            stagingPackCheck($spot->source === $r['source'] && $spot->source_id === $r['source_id'], 'Source identity changed');
            stagingPackCheck($spot->getRawOriginal('category') === $r['category'], 'Category differs from reviewed source');
            stagingPackCheck(abs($facts['location']['map_point']['lat'] - $r['lat']) < 0.000001 && abs($facts['location']['map_point']['lng'] - $r['lng']) < 0.000001, 'Resolved coordinate mismatch');
            stagingPackCheck(preparedPlaceRowFingerprint(['tags' => $spot->tags]) === preparedPlaceRowFingerprint(['tags' => $r['tags'] ?: null]), 'Practical source tags lost');
            stagingPackCheck(! in_array($facts['access']['value'], ['private', 'no', 'customers', 'members', 'permit'], true) && $facts['access']['status'] !== 'conflicting', 'Restricted facts entered ready batch');
            if ($r['observation']['fee']['raw'] === null) {
                stagingPackCheck($facts['fee']['value'] === 'unknown', 'Missing fee became free or paid');
            }
            stagingPackCheck($facts['name_kind'] === $r['name_kind'] && $facts['name']['value'] === $acceptance['entries'][$key]['display_name'] && ($r['name_kind'] !== 'source' || $facts['name']['value'] === $r['name']), 'Resolved name differs from reviewed source');
            $body = stagingPackApi('/api/places/'.$id, $user);
            $data = $body['data'] ?? [];
            stagingPackCheck(($data['id'] ?? null) === $id && isset($data['place_facts']), 'Place detail contract mismatch');
            stagingPackCheck($data['name'] === $facts['name']['value'] && $data['lat'] === (float) $facts['location']['map_point']['lat'] && $data['lng'] === (float) $facts['location']['map_point']['lng'], 'API name or coordinate mismatch');
            $expectedApiFacts = array_intersect_key($facts, array_flip(['identity', 'name_kind', 'aliases', 'location', 'access', 'fee', 'hours', 'contact', 'description', 'negative_facts', 'practical', 'activities', 'conflicts', 'revision']));
            stagingPackCheck(preparedPlaceRowFingerprint($data['place_facts']) === preparedPlaceRowFingerprint($expectedApiFacts), 'API facts differ from shared resolver');
            if ($facts['fee']['value'] === 'unknown') {
                stagingPackCheck($data['price_text'] === null, 'API labels unknown fee as known');
            }
            $out['detail_api_checks']++;
        }
        $candidates = app(CandidateRepository::class)->byIds(array_map(fn ($id) => 'spot:'.$id, $ids), CarbonImmutable::now('Europe/Berlin')->startOfDay());
        $actualComposer = array_map(fn ($c) => $c->id, $candidates);
        sort($actualComposer);
        sort($expectedComposer);
        stagingPackCheck(count($expectedComposer) === 87 && $actualComposer === $expectedComposer, 'Composer differs from the exact frozen expected identity set');
        $out['composer_identities'] = count($candidates);
        $out['exact_composer_identity_set_passed'] = true;
        foreach ($candidates as $candidate) {
            $id = (int) substr($candidate->id, 5);
            $spot = Spot::findOrFail($id);
            $facts = app(PlaceFacts::class)->resolve($spot);
            stagingPackCheck($helper->hash($candidate->placeFacts) === $helper->hash($facts), 'Composer and Places fact resolver differ');
            stagingPackCheck($candidate->category === $byId[$id]['category'], 'Composer category mismatch');
            if ($byId[$id]['observation']['fee']['raw'] === null && $spot->price_range === null) {
                stagingPackCheck($candidate->costTier === 'unknown', 'Composer promotes unknown cost to free');
            }
        }
        $out['held_from_composer'] = count($ids) - count($candidates);
        $out['recovery_proof'] = $journal->rehearseRecovery($operation, $context, $actor, 'Prove exact native recovery before the staging canary may be committed.');
        $again = $journal->apply($operation, $context, $selected, $baseline, $actor);
        stagingPackCheck($again['sha256'] === $receipt['sha256'], 'Repeat import changed the journal');
        $out['apply_replay_noop'] = true;
    }
    $prepared = DB::table('place_catalogue_operations')->where('id', $operation)->first();
    $receiptName = 'canary-prepared-'.$operation.'-'.substr($runnerHash, 0, 16).'.json';
    $path = $input.'/'.$receiptName;
    $serialized = json_encode(['phase' => 'prepared_uncommitted', 'mode' => $mode, 'operation_id' => $operation, 'runner_sha256' => $runnerHash, 'database_journal_is_authoritative' => true, 'journal_row' => (array) $prepared], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    $temporaryPath = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
    $stream = fopen($temporaryPath, 'xb');
    stagingPackCheck($stream !== false, 'Cannot open private prepared receipt');
    try {
        stagingPackCheck(fwrite($stream, $serialized) === strlen($serialized) && fflush($stream) && fsync($stream), 'Cannot flush private prepared receipt');
    } finally {
        fclose($stream);
    }
    stagingPackCheck(rename($temporaryPath, $path) && hash_file('sha256', $path) === hash('sha256', $serialized), 'Atomic private prepared receipt export failed');
    $out['private_prepared_receipt_file'] = $receiptName;
    $out['private_prepared_receipt_sha256'] = hash('sha256', $serialized);
    if ($mode === '--rehearse') {
        DB::rollBack();
        $out['rollback'] = true;
        foreach ($before as $table => $hash) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            stagingPackCheck($helper->hash($rows) === $hash, 'Catalogue changed after rehearsal rollback');
        }
        DB::rollBack();
        $out['status'] = 'passed_rolled_back';
    } else {
        DB::commit();
        DB::commit();
        $out['status'] = 'committed';
    }
} catch (Throwable $error) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    throw $error;
}
$out['finished_at'] = gmdate('c');
file_put_contents($input.'/canary-summary.json', json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($out, JSON_THROW_ON_ERROR).PHP_EOL;
