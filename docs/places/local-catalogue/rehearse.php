<?php

require __DIR__.'/bootstrap-local.php';
require __DIR__.'/../production-pack/apply-pack.php';
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Http\Resources\PlaceResource;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$user = localUser();
$private = getenv('PLACES_LOCAL_PRIVATE');
$manifest = json_decode(file_get_contents($private.'/supported-package/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
localCheck(hash_file('sha256', $private.'/places-only.json') === $manifest['baseline_sha256']
    && hash_file('sha256', $private.'/supported-package/records.jsonl') === $manifest['records_sha256'], 'Prepared inputs changed.');
$records = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($private.'/supported-package/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
localCheck(count($records) === $manifest['records'] && count(array_unique(array_column($records, 'key'))) === count($records), 'Duplicate prepared source identity.');
$baseline = json_decode(file_get_contents($private.'/places-only.json'), true, flags: JSON_THROW_ON_ERROR);
$beforeById = array_column(localTypedRows('spots'), null, 'id');
localCheck(preparedPlaceRowFingerprint($beforeById) === preparedPlaceRowFingerprint(array_column($baseline['tables']['spots'], null, 'id')), 'Frozen baseline differs.');
$pdo = DB::connection()->getPdo();
$snapshot = localSnapshot();
localVerify($pdo, $snapshot);
$beforeHashes = [];
$sequences = [];
foreach (array_keys($snapshot['tables']) as $table) {
    $beforeHashes[$table] = localHash(localTypedRows($table));
    $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", [$table])->name;
    if ($seq !== null) {
        localCheck((bool) preg_match('/^public\.[a-z_]+$/', $seq), 'Unexpected sequence name.');
        $state = DB::selectOne('SELECT last_value,is_called FROM '.$seq);
        $sequences[$seq] = [$state->last_value, $state->is_called];
    }
}
$grouping = app(DestinationGrouping::class);
$beforeEligible = $grouping->eligible(Spot::query(), true)->count();
$beforeGeneral = $grouping->general(Spot::query())->count();
DB::select("SELECT pg_advisory_lock(hashtext('exp69_local_catalogue_rehearsal'))");
DB::beginTransaction();
try {
    $mapping = applyPreparedPlaces($records, $beforeById, $manifest['records_sha256']);
    $afterById = array_column(localTypedRows('spots'), null, 'id');
    $refreshes = array_fill_keys(array_filter(array_column($records, 'existing_id')), true);
    foreach ($beforeById as $id => $before) {
        if (! isset($refreshes[$id])) {
            localCheck(preparedPlaceRowFingerprint($afterById[$id]) === preparedPlaceRowFingerprint($before), 'Unrelated place changed.');
        }
        foreach (['parent_spot_id', 'canonical_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id', 'destination_reviewed_at', 'destination_grouping_evidence', 'photo_url', 'photo_attribution'] as $key) {
            localCheck($before[$key] === $afterById[$id][$key], 'Protected grouping or media changed.');
        }
    }
    foreach ($beforeHashes as $table => $hash) {
        if (! in_array($table, ['spots', 'place_fact_observations', 'place_fact_revisions'], true)) {
            localCheck(localHash(localTypedRows($table)) === $hash, 'Protected table changed: '.$table);
        }
    }
    $day = CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Berlin');
    $eligible = $grouping->eligible(Spot::query(), true)->whereIn('spots.id', array_column($mapping, 'id'))->pluck('spots.id')->all();
    $verified = $composerVerified = 0;
    $apiSamples = [];
    DB::statement('ANALYZE spots');
    DB::statement('ANALYZE place_fact_observations');
    $recordsById = [];
    foreach ($records as $record) {
        $recordsById[$mapping[$record['key']]['id']] = $record;
    }
    $request = Request::create('/api/places', 'GET');
    $request->setUserResolver(static fn () => $user);
    foreach (array_chunk(array_keys($recordsById), 150) as $chunk) {
        $spots = Spot::whereIn('id', $chunk)->with(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset'])->get();
        app(PlaceFacts::class)->attach($spots);
        $candidates = app(CandidateRepository::class)->byIds(array_map(static fn (int $id): string => 'spot:'.$id, $chunk), $day);
        $byId = array_column($candidates, null, 'id');
        foreach ($spots as $spot) {
            $id = $spot->id;
            $record = $recordsById[$id];
            $facts = $spot->getRelation(PlaceFacts::SNAPSHOT_RELATION);
            localCheck($spot->source === $record['source'] && $spot->source_id === $record['source_id']
                && abs($facts['location']['map_point']['lat'] - $record['lat']) < 0.000001
                && abs($facts['location']['map_point']['lng'] - $record['lng']) < 0.000001, 'Native source identity or location mismatch.');
            localCheck(preparedPlaceRowFingerprint(['tags' => $spot->tags]) === preparedPlaceRowFingerprint(['tags' => $record['tags'] ?: null]), 'Practical tags changed.');
            $spot->recommendation_available = in_array($id, $eligible, true);
            $body = (new PlaceResource($spot))->resolve($request);
            localCheck($body['id'] === $id && trim($body['name']) !== '' && $body['place_facts']['name_kind'] === $record['name_kind'], 'Prepared API resource mismatch.');
            $sampleKey = $record['source'].':'.$record['category'].':'.$record['name_kind'];
            if (! isset($apiSamples[$sampleKey])) {
                $sample = localApi('/api/places/'.$id, $user)['data'];
                localCheck($sample['id'] === $id && $sample['name'] === $body['name'] && $sample['place_facts'] === $body['place_facts'], 'Kernel API sample differs from native resource.');
                $apiSamples[$sampleKey] = $id;
            }
            if ($record['observation']['fee']['raw'] === null) {
                localCheck($facts['fee']['value'] === 'unknown', 'Missing fee became a claim.');
            }
            if (in_array($id, $eligible, true)) {
                $candidate = $byId['spot:'.$id] ?? null;
                localCheck($candidate !== null && $candidate->name === $body['name']
                    && $candidate->lat === $body['routing_lat'] && $candidate->lng === $body['routing_lng']
                    && $candidate->placeFacts === $facts, 'Prepared Composer identity differs.');
                if ($facts['fee']['value'] === 'unknown') {
                    localCheck($candidate->costTier === 'unknown', 'Composer inferred missing price.');
                    $free = new Constraints($day, $day->addHours(10), budget: 'free');
                    localCheck(app(FeasibilityFilter::class)->filter($free, [$candidate]) === [], 'Unknown fee entered strict-free results.');
                }
                $composerVerified++;
            } else {
                localCheck(! isset($byId['spot:'.$id]), 'Unqualified record entered Composer.');
            }
            $verified++;
        }
        echo json_encode(['prepared_contracts_checked' => $verified, 'total' => count($records)]).PHP_EOL;
    }
    $afterHashes = [];
    foreach (array_keys($snapshot['tables']) as $table) {
        $afterHashes[$table] = localHash(localTypedRows($table));
    }
    $replay = $records;
    foreach ($replay as &$record) {
        $record['existing_id'] = $mapping[$record['key']]['id'];
    }
    unset($record);
    applyPreparedPlaces($replay, $afterById, $manifest['records_sha256']);
    foreach ($afterHashes as $table => $hash) {
        localCheck(localHash(localTypedRows($table)) === $hash, 'Replay was not a no-op: '.$table);
    }
    $report = ['status' => 'passed', 'checked_at' => gmdate('c'), 'baseline_sha256' => $manifest['baseline_sha256'],
        'records_sha256' => $manifest['records_sha256'], 'records_verified' => $verified,
        'creates' => count($afterById) - count($beforeById), 'refreshes' => count($refreshes),
        'eligible_before' => $beforeEligible, 'eligible_during_rehearsal' => $grouping->eligible(Spot::query(), true)->count(),
        'general_before' => $beforeGeneral, 'general_during_rehearsal' => $grouping->general(Spot::query())->count(),
        'selected_composer_candidates_verified' => $composerVerified, 'kernel_api_samples' => count($apiSamples), 'replay_exact_no_op' => true,
        'protected_identity_and_media_unchanged' => true, 'new_photos_approved' => 0, 'remote_changed' => false];
} finally {
    DB::rollBack();
    foreach ($sequences as $seq => [$value,$called]) {
        DB::select('SELECT setval(?::regclass, ?, ?::boolean)', [$seq, $value, $called ? 'true' : 'false']);
    }
    DB::select("SELECT pg_advisory_unlock(hashtext('exp69_local_catalogue_rehearsal'))");
}
foreach ($beforeHashes as $table => $hash) {
    localCheck(localHash(localTypedRows($table)) === $hash, 'Rollback changed table '.$table);
}
foreach ($sequences as $seq => [$value,$called]) {
    $state = DB::selectOne('SELECT last_value,is_called FROM '.$seq);
    localCheck($state->last_value === $value && $state->is_called === $called, 'Sequence rollback mismatch.');
}
localVerify(localPdo(), $snapshot);
$report['exact_baseline_and_sequence_restore_verified'] = true;
$report['real_users_imported'] = 0;
localSummary('rehearsal-summary.json', $report);
