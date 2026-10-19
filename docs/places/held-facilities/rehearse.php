<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Http\Resources\PlaceResource;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\ReviewPlaceFacts;
use App\Services\NearbyPlaces;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../local-catalogue/bootstrap-local.php';
require __DIR__.'/../production-pack/apply-pack.php';
require __DIR__.'/../local-catalogue/FacilityQualificationJournal.php';
require __DIR__.'/native-checks.php';

$private = getenv('PLACES_LOCAL_PRIVATE');
$output = $private.'/held-public-facilities';
$read = static fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$selectionSummary = $read(__DIR__.'/2026-10-01/selection-summary.json');
$sourceSummary = $read(__DIR__.'/2026-10-01/source-summary.json');
localCheck(hash_file('sha256', $output.'/selection.json') === $selectionSummary['selection_sha256']
    && hash_file('sha256', $output.'/source-proof.json') === $sourceSummary['source_proof_sha256']
    && $sourceSummary['selection_sha256'] === $selectionSummary['selection_sha256'], 'Frozen source evidence changed.');
foreach ($selectionSummary['input_sha256'] as $path => $sha) {
    $path = $path === 'inventory' ? getenv('PLACES_SOURCE_ROOT').'/docs/places/cologne-expansion/2026-09-28/inventory.sqlite' : $private.'/'.$path;
    localCheck(hash_file('sha256', $path) === $sha, 'Selection baseline input changed.');
}
$runtimeSummary = $read(__DIR__.'/2026-10-01/runtime-summary.json');
localCheck(hash_file('sha256', $output.'/runtime-manifest.json') === $runtimeSummary['runtime_manifest_sha256'], 'Runtime manifest changed.');
$runtime = $read($output.'/runtime-manifest.json');
localCheck(hash_file('sha256', __DIR__.'/../local-catalogue/FacilityQualificationJournal.php') === $runtime['facility_helper_sha256'], 'Qualification journal changed.');
foreach ($runtime['files'] as $path => $sha) {
    localCheck(hash_file('sha256', getcwd().'/'.$path) === $sha, 'Native runtime changed.');
}
$selection = $read($output.'/selection.json');
$proof = $read($output.'/source-proof.json');
$user = localUser();
$api = static function (string $path) use ($user): array {
    usleep(1050000);

    return localApi($path, $user);
};
$grouping = app(DestinationGrouping::class);
$repository = app(CandidateRepository::class);
$review = app(ReviewPlaceFacts::class);
$day = CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Berlin');
$constraints = static fn (string $category, ?string $budget = null, array $activities = [], float $radius = .1): Constraints => Constraints::fromArray([
    'window_start' => '2026-10-01T14:00:00+02:00', 'window_end' => '2026-10-01T18:00:00+02:00',
    'categories' => [$category], 'activities' => $activities, 'budget' => $budget, 'radius_km' => $radius,
]);
$path = $output.'/candidate-places.jsonl';
$temporary = $output.'/candidate-places.partial.jsonl';
localCheck(! file_exists($path) && ! file_exists($temporary), 'Preserve previous combined export.');
DB::select("SELECT pg_advisory_lock(hashtext('exp69_local_catalogue_rehearsal'))");
$snapshot = localSnapshot();
localVerify(localPdo(), $snapshot);
$before = array_column(localTypedRows('spots'), null, 'id');
$beforeEligible = $grouping->eligible(Spot::query(), true)->count();
$generalBefore = $grouping->general(Spot::query())->count();
$sequences = [];
foreach (array_keys($snapshot['tables']) as $table) {
    $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", [$table])->name;
    if ($seq !== null) {
        localCheck((bool) preg_match('/^public\.[a-z_]+$/', $seq), 'Unexpected sequence.');
        $state = DB::selectOne('SELECT last_value,is_called FROM '.$seq);
        $sequences[$seq] = [$state->last_value, $state->is_called];
    }
}
$handle = fopen($temporary, 'x');
$success = false;
DB::beginTransaction();
try {
    [$prepared, $held] = heldPreparedRecords($selection, $proof, $before);
    localCheck(count($prepared) > 0 && count($prepared) + count($held) === count($selection['records']), 'Every selected identity needs a disposition.');
    file_put_contents($output.'/native-preparation.json', json_encode(['records' => $prepared, 'held' => $held], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['phase' => 'native_source_checks', 'prepared' => count($prepared), 'held' => count($held)]).PHP_EOL;

    $first = $prepared[0];
    $changed = $selection;
    $changed['records'] = [array_replace($first, ['source_id' => 'node/0'])];
    [$invalid] = heldPreparedRecords($changed, $proof, $before);
    localCheck($invalid === [], 'Mismatched source owner was accepted.');
    $changed['records'] = [$first];
    $badBefore = $before;
    $badBefore[$first['legacy_counterpart_ids'][0]]['is_active'] = true;
    [$invalid] = heldPreparedRecords($changed, $proof, $badBefore);
    localCheck($invalid === [], 'Reactivated legacy counterpart was accepted.');

    // Stored representative points may shift slightly; the fresh complete source
    // geometry still must match the frozen candidate within one metre.
    $boundarySpot = Spot::findOrFail($first['existing_id']);
    DB::beginTransaction();
    try {
        foreach ([24 => true, 26 => false] as $metres => $accepted) {
            $boundarySpot->update(['lat' => $first['lat'] + $metres / 111194.92664455874, 'lng' => $first['lng']]);
            [$boundaryPrepared] = heldPreparedRecords(['records' => [$first]], $proof, $before);
            localCheck((count($boundaryPrepared) === 1) === $accepted, 'Stored-point update boundary differs at '.$metres.' metres.');
        }
    } finally {
        DB::rollBack();
    }

    $packHash = hash('sha256', json_encode($prepared, JSON_THROW_ON_ERROR));
    applyPreparedPlaces($prepared, $before, $packHash);
    $refreshed = array_column(localTypedRows('spots'), null, 'id');
    $observationsHash = localHash(localTypedRows('place_fact_observations'));
    applyPreparedPlaces($prepared, $refreshed, $packHash);
    localCheck(localHash(localTypedRows('spots')) === localHash(array_values($refreshed))
        && localHash(localTypedRows('place_fact_observations')) === $observationsHash, 'Source refresh replay changed state.');
    $preparedIds = array_column($prepared, 'existing_id');
    foreach ($before as $id => $row) {
        if (! in_array($id, $preparedIds, true)) {
            localCheck(preparedPlaceRowFingerprint($row) === preparedPlaceRowFingerprint($refreshed[$id]), 'Unrelated or legacy row changed.');
        }
        foreach (['source', 'source_id', 'canonical_spot_id', 'parent_spot_id', 'destination_spot_id', 'is_active', 'is_recommendable'] as $field) {
            localCheck($row[$field] === $refreshed[$id][$field], 'Protected identity or eligibility changed.');
        }
    }
    localCheck($grouping->eligible(Spot::query(), true)->count() === $beforeEligible, 'Refresh alone qualified a facility.');
    $newPackages = [];
    foreach ($prepared as $record) {
        heldAssertFacts($record);
        $preview = $review->preview($record['existing_id'], ['activity_discovery' => true]);
        $evidence = 'Current OSM source and full geometry checked at '.$proof['summary']['checked_at'].'; exact existing source identity; legacy overlaps remain unavailable and unchanged. '.$record['source_url'];
        $review->apply($record['existing_id'], ['activity_discovery' => true], $preview['fingerprint'], $evidence, 'local-held-facility-review');
        $newPackages[] = ['spot_id' => $record['existing_id'], 'fingerprint' => $preview['fingerprint'], 'evidence' => $evidence, 'expected_fee' => $record['expected_fee']];
    }
    $qualifiedHash = localHash(localTypedRows('place_fact_corrections'));
    $qualifiedRevision = localHash(localTypedRows('place_fact_revisions'));
    foreach ($newPackages as $record) {
        $preview = $review->preview($record['spot_id'], ['activity_discovery' => true]);
        $review->apply($record['spot_id'], ['activity_discovery' => true], $preview['fingerprint'], $record['evidence'], 'local-held-facility-review');
    }
    localCheck(localHash(localTypedRows('place_fact_corrections')) === $qualifiedHash
        && localHash(localTypedRows('place_fact_revisions')) === $qualifiedRevision, 'Qualification replay changed audit rows.');
    localCheck($grouping->eligible(Spot::query(), true)->count() === $beforeEligible + count($prepared), 'Qualification count differs.');
    file_put_contents($output.'/qualification-proposal.json', json_encode($newPackages, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['phase' => 'new_facilities_qualified', 'count' => count($prepared)]).PHP_EOL;

    $samples = [];
    $freeIds = [];
    foreach ($prepared as $record) {
        $facts = heldAssertFacts($record);
        $samples[$record['category'].':'.$record['expected_fee']] ??= $record;
        if (in_array('soccer', $facts['activities'], true) || $record['expected_fee'] === 'free') {
            $samples['record:'.$record['existing_id']] = $record;
        }
        if ($record['expected_fee'] === 'free') {
            $freeIds[] = $record['existing_id'];
        }
    }
    $sampleIds = $soccerIds = [];
    foreach ($samples as $record) {
        if (in_array($record['existing_id'], $sampleIds, true)) {
            continue;
        }
        $spot = Spot::findOrFail($record['existing_id']);
        $body = $api('/api/places/'.$spot->id)['data'];
        localCheck($body['recommendation_status'] === 'available' && $body['place_facts']['fee']['value'] === $record['expected_fee'], 'Native detail differs.');
        $map = $api('/api/spots?'.http_build_query(['sw_lat' => $spot->lat - .001, 'ne_lat' => $spot->lat + .001, 'sw_lng' => $spot->lng - .001, 'ne_lng' => $spot->lng + .001, 'category' => $record['category'], 'limit' => 200]));
        localCheck(in_array($spot->id, array_column($map, 'id'), true), 'Native category map omitted facility.');
        $activities = in_array('soccer', $body['place_facts']['activities'], true) ? ['soccer'] : [];
        $candidates = $repository->candidatesFor($constraints($record['category'], null, $activities), $spot->lat, $spot->lng);
        localCheck(in_array('spot:'.$spot->id, array_column($candidates, 'id'), true), 'Nearby Composer omitted facility.');
        $free = $repository->candidatesFor($constraints($record['category'], 'free', $activities), $spot->lat, $spot->lng);
        localCheck(in_array('spot:'.$spot->id, array_column($free, 'id'), true) === ($record['expected_fee'] === 'free'), 'Strict-free discovery disagrees with evidence.');
        $sampleIds[] = $spot->id;
        if ($activities !== []) {
            $soccerIds[] = $spot->id;
        }
    }
    $listed = [];
    foreach (array_unique(array_column($prepared, 'category')) as $category) {
        $page = 1;
        do {
            $body = $api('/api/places?'.http_build_query(['activity' => $category, 'page' => $page]));
            $listed = [...$listed, ...array_column($body['data'], 'id')];
            $page++;
        } while ($page <= $body['meta']['last_page']);
    }
    localCheck(array_diff($preparedIds, $listed) === [], 'Paginated activity discovery missed a facility.');
    localCheck($grouping->general(Spot::query())->count() === $generalBefore, 'Activity qualification changed general discovery.');

    $manifest = $read($private.'/supported-package/manifest.json');
    localCheck(hash_file('sha256', $private.'/supported-package/records.jsonl') === $manifest['records_sha256'], 'Previous addition package changed.');
    $additions = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($private.'/supported-package/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    applyPreparedPlaces($additions, array_column(localTypedRows('spots'), null, 'id'), $manifest['records_sha256']);
    $oldRecords = $read($private.'/facility-qualification-package-private.json');
    $oldSource = $read($private.'/facility-source-private.json');
    localCheck(CarbonImmutable::parse($oldSource['summary']['checked_at'])->betweenIncluded(now()->subDay(), now()), 'Previous cohort proof expired.');
    foreach ($oldRecords as &$record) {
        $record['fingerprint'] = $review->preview($record['spot_id'], ['activity_discovery' => true])['fingerprint'];
    }
    unset($record);
    localCheck(array_intersect($preparedIds, array_column($oldRecords, 'spot_id')) === [], 'Old and new cohorts overlap.');
    $journal = new FacilityQualificationJournal;
    $context = ['database' => 'exp69_local_catalogue_20261001', 'package_sha256' => FacilityQualificationJournal::hash($oldRecords), 'application_sha256' => FacilityQualificationJournal::hash($runtime), 'importer_sha256' => hash_file('sha256', __DIR__.'/../local-catalogue/FacilityQualificationJournal.php')];
    $operation = '72c01001-2026-4101-8000-000000000003';
    $receipt = $journal->apply($operation, $context, $oldRecords, $journal->snapshot(array_column($oldRecords, 'spot_id')), 'local-catalogue-review');
    $ids = $grouping->eligible(Spot::query(), true)->orderBy('spots.id')->pluck('spots.id')->all();
    localCheck(count($ids) === $beforeEligible + count($prepared) + count($additions) + count($oldRecords), 'Combined count differs.');
    $request = Request::create('/api/places', 'GET');
    $request->setUserResolver(static fn () => $user);
    $count = $photos = 0;
    $categories = $newCategories = [];
    $preparedById = array_column($prepared, null, 'existing_id');
    foreach (array_chunk($ids, 200) as $chunk) {
        $spots = Spot::whereIn('id', $chunk)->with(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset'])->orderBy('id')->get();
        app(PlaceFacts::class)->attach($spots);
        $candidates = array_column($repository->byIds(array_map(static fn (int $id): string => 'spot:'.$id, $chunk), $day), null, 'id');
        foreach ($spots as $spot) {
            $spot->recommendation_available = true;
            $body = (new PlaceResource($spot))->resolve($request);
            $candidate = $candidates['spot:'.$spot->id] ?? null;
            $facts = $spot->getRelation(PlaceFacts::SNAPSHOT_RELATION);
            localCheck($candidate !== null && $candidate->name === $body['name'] && $candidate->placeFacts === $facts
                && $candidate->lat === $body['routing_lat'] && $candidate->lng === $body['routing_lng']
                && ($facts['fee']['value'] !== 'unknown' || $candidate->costTier === 'unknown'), 'Combined native contract differs.');
            if (isset($preparedById[$spot->id])) {
                $record = $preparedById[$spot->id];
                localCheck(app(FeasibilityFilter::class)->matchesDiscovery($constraints($record['category'], 'free', [], 100), $candidate) === ($record['expected_fee'] === 'free'), 'All-record strict-free contract differs.');
                $newCategories[$spot->category->value] = ($newCategories[$spot->category->value] ?? 0) + 1;
            }
            fwrite($handle, json_encode(['schema_version' => 1, 'id' => 'spot:'.$spot->id, 'source' => ['provider' => $spot->source, 'record_id' => $spot->source_id], 'place' => $body, 'composer' => $candidate, 'scope' => 'local_candidate_catalogue_not_deployed', 'source_currentness' => 'per_field_evidence_dates'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            $photos += (int) ($body['photo_url'] !== null);
            $categories[$spot->category->value] = ($categories[$spot->category->value] ?? 0) + 1;
            $count++;
        }
        echo json_encode(['phase' => 'combined_contracts', 'verified' => $count, 'total' => count($ids)]).PHP_EOL;
    }
    $refreshBeforeRecovery = localHash(localTypedRows('place_fact_observations'));
    foreach ($newPackages as $record) {
        $preview = $review->preview($record['spot_id'], ['activity_discovery' => null]);
        $review->apply($record['spot_id'], ['activity_discovery' => null], $preview['fingerprint'], 'Revoke the local-only qualification while preserving refreshed source evidence and legacy references.', 'local-held-facility-recovery');
    }
    $recoveredHash = localHash(localTypedRows('place_fact_corrections'));
    foreach ($newPackages as $record) {
        $preview = $review->preview($record['spot_id'], ['activity_discovery' => null]);
        $review->apply($record['spot_id'], ['activity_discovery' => null], $preview['fingerprint'], 'Revoke the local-only qualification while preserving refreshed source evidence and legacy references.', 'local-held-facility-recovery');
    }
    localCheck(localHash(localTypedRows('place_fact_corrections')) === $recoveredHash
        && localHash(localTypedRows('place_fact_observations')) === $refreshBeforeRecovery
        && $grouping->eligible(Spot::query(), true)->whereIn('spots.id', $preparedIds)->count() === 0, 'Qualification recovery changed source evidence or failed.');
    localCheck($repository->byIds(array_map(static fn (int $id): string => 'spot:'.$id, $preparedIds), $day) === [], 'Recovered records remain in Composer.');
    foreach (array_slice($sampleIds, 0, 8) as $id) {
        localCheck($api('/api/places/'.$id)['data']['recommendation_status'] === 'unavailable', 'Recovered detail remains available.');
    }
    $journal->recover($operation, $context, 'local-catalogue-recovery', 'Recover the prior facility proposal after the combined local-only rehearsal.');
    localCheck($grouping->eligible(Spot::query(), true)->count() === $beforeEligible + count($additions), 'Recovery removed independent additions.');
    $pointUpdates = array_filter($prepared, static fn (array $record): bool => NearbyPlaces::km($before[$record['existing_id']]['lat'], $before[$record['existing_id']]['lng'], $record['lat'], $record['lng']) * 1000 > 1);
    $maxPointUpdate = max(array_map(static fn (array $record): float => NearbyPlaces::km($before[$record['existing_id']]['lat'], $before[$record['existing_id']]['lng'], $record['lat'], $record['lng']) * 1000, $prepared));
    $report = ['representative_point_updates_above_one_metre' => count($pointUpdates), 'maximum_representative_point_update_metres' => round($maxPointUpdate, 3), 'stored_point_25m_boundary_verified' => true, 'status' => 'passed', 'checked_at' => gmdate('c'), 'selected_source_records' => count($selection['records']), 'qualified_facilities' => count($prepared), 'held_after_native_checks' => count($held), 'held_reasons' => array_count_values(array_merge([], ...array_column($held, 'reasons'))), 'new_facilities_by_category' => $newCategories, 'explicit_free_facilities' => count($freeIds), 'unknown_fee_facilities' => count($prepared) - count($freeIds), 'nearby_soccer_facilities_verified' => count($soccerIds), 'native_detail_map_nearby_samples' => count($sampleIds), 'all_new_facilities_in_paginated_discovery' => count($prepared), 'all_new_fee_constraints_verified' => count($prepared), 'combined_candidates' => $count, 'general_destinations' => $generalBefore + count($additions), 'combined_by_category' => $categories, 'policy_publishable_hero_associations' => $photos, 'new_media_approvals' => 0, 'baseline_eligible' => $beforeEligible, 'previous_proposal' => $beforeEligible + count($additions) + count($oldRecords), 'source_refresh_replay_identical' => true, 'qualification_and_recovery_replay_identical' => true, 'legacy_rows_preserved' => true, 'qualification_recovery_preserved_source_refresh' => true, 'actual_process_crash_tested' => false, 'durable_live_operator_prepared' => false, 'source_checked_at' => $proof['summary']['checked_at'], 'selection_sha256' => $selectionSummary['selection_sha256'], 'source_proof_sha256' => $sourceSummary['source_proof_sha256'], 'runtime_files_verified' => count($runtime['files']), 'runtime_manifest_sha256' => $runtimeSummary['runtime_manifest_sha256'], 'remote_changed' => false];
    $success = true;
} finally {
    DB::rollBack();
    fclose($handle);
    Cache::flush();
    foreach ($sequences as $seq => [$value, $called]) {
        DB::select('SELECT setval(?::regclass, ?, ?::boolean)', [$seq, $value, $called ? 'true' : 'false']);
    }
    if (! $success) {
        unlink($temporary);
    }
}
try {
    localVerify(localPdo(), $snapshot);
    foreach ($sequences as $seq => [$value, $called]) {
        $state = DB::selectOne('SELECT last_value,is_called FROM '.$seq);
        localCheck($state->last_value === $value && $state->is_called === $called, 'Sequence restoration differs.');
    }
    localCheck(DB::table('place_catalogue_operations')->count() === 0, 'Trial operation escaped rollback.');
    $report['exact_baseline_restored'] = true;
    $report['sequences_restored'] = true;
    $report['candidate_export_sha256'] = hash_file('sha256', $temporary);
    rename($temporary, $path);
    file_put_contents(__DIR__.'/2026-10-01/rehearsal-summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    echo json_encode($report).PHP_EOL;
} finally {
    DB::select("SELECT pg_advisory_unlock(hashtext('exp69_local_catalogue_rehearsal'))");
}
