<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Enums\SpotCategory;
use App\Http\Resources\PlaceResource;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\ReviewPlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../local-catalogue/bootstrap-local.php';
require __DIR__.'/../production-pack/apply-pack.php';
require __DIR__.'/../local-catalogue/FacilityQualificationJournal.php';
require __DIR__.'/../held-facilities/native-checks.php';
require __DIR__.'/native-checks.php';

$private = getenv('PLACES_LOCAL_PRIVATE');
$output = $private.'/ten-thousand-catalogue';
$read = static fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$selectionSummary = $read(__DIR__.'/2026-10-01/selection-summary.json');
$sourceSummary = $read(__DIR__.'/2026-10-01/source-summary.json');
$nativeSummary = $read(__DIR__.'/2026-10-01/native-source-summary.json');
$runtimeSummary = $read(__DIR__.'/2026-10-01/runtime-summary.json');
$runtime = $read($output.'/runtime-manifest.json');
foreach ($runtime['files'] as $path => $sha) {
    localCheck(hash_file('sha256', getcwd().'/'.$path) === $sha, 'Native runtime changed.');
}
localCheck(hash_file('sha256', $output.'/runtime-manifest.json') === $runtimeSummary['runtime_manifest_sha256'], 'Runtime manifest changed.');
foreach ($selectionSummary['inputs_sha256'] as $name => $sha) {
    $path = $name === 'inventory' ? getenv('PLACES_SOURCE_ROOT').'/docs/places/cologne-expansion/2026-09-28/inventory.sqlite' : $private.'/'.$name;
    localCheck(hash_file('sha256', $path) === $sha, 'Frozen input changed.');
}
foreach (['selection.json' => $selectionSummary['selection_sha256'], 'source-proof.json' => $sourceSummary['source_proof_sha256'], 'native-preparation.json' => $nativeSummary['native_preparation_sha256'], 'type-map.json' => $nativeSummary['type_map_sha256']] as $name => $sha) {
    localCheck(hash_file('sha256', $output.'/'.$name) === $sha, 'Frozen additional-place evidence changed.');
}
localCheck($sourceSummary['selection_sha256'] === $selectionSummary['selection_sha256'], 'Source proof belongs to another selection.');
$oldHeldSelection = $read($private.'/held-public-facilities/selection.json');
$oldHeldProof = $read($private.'/held-public-facilities/source-proof.json');
$oldHeldSummary = $read(__DIR__.'/../held-facilities/2026-10-01/source-summary.json');
localCheck(hash_file('sha256', $private.'/held-public-facilities/source-proof.json') === $oldHeldSummary['source_proof_sha256']
    && hash_file('sha256', $private.'/held-public-facilities/selection.json') === $oldHeldSummary['selection_sha256'], 'Previous facility source proof changed.');
$oldRecords = $read($private.'/facility-qualification-package-private.json');
$oldSource = $read($private.'/facility-source-private.json');
localCheck(CarbonImmutable::parse($oldSource['summary']['checked_at'])->betweenIncluded(now()->subDay(), now()), 'Previous facility evidence expired.');
$oldManifest = $read($private.'/supported-package/manifest.json');
localCheck(hash_file('sha256', $private.'/supported-package/records.jsonl') === $oldManifest['records_sha256'], 'Previous additive package changed.');
$oldAdditions = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($private.'/supported-package/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$prior = [];
$previous = fopen($private.'/held-public-facilities/candidate-places.jsonl', 'r');
while (($line = fgets($previous)) !== false) {
    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $prior[$record['id']] = ['name' => $record['place']['name'], 'source' => $record['source'], 'category' => $record['composer']['category'], 'lat' => $record['composer']['lat'], 'lng' => $record['composer']['lng'], 'fee' => $record['place']['place_facts']['fee']['value'], 'photo_url' => $record['place']['photo_url']];
}
fclose($previous);
localCheck(count($prior) === 4927, 'Previous verified catalogue count differs.');
$user = localUser();
$grouping = app(DestinationGrouping::class);
$repository = app(CandidateRepository::class);
$review = app(ReviewPlaceFacts::class);
$day = CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Berlin');
$constraints = static fn (string $selector, ?string $budget = null, ?float $radius = .1): Constraints => Constraints::fromArray(['window_start' => '2026-10-01T14:00:00+02:00', 'window_end' => '2026-10-01T18:00:00+02:00', 'categories' => [$selector], 'budget' => $budget, 'radius_km' => $radius]);
$failureMode = getenv('PLACES_EXPECTED_FAILURE') === 'true';
$path = $output.'/candidate-places.jsonl';
$temporary = $output.($failureMode ? '/failure-check.partial.jsonl' : '/candidate-places.partial.jsonl');
localCheck(! file_exists($temporary) && ($failureMode || ! file_exists($path)), 'Preserve previous candidate output.');
DB::select("SELECT pg_advisory_lock(hashtext('exp69_local_catalogue_rehearsal'))");
$snapshot = localSnapshot();
localVerify(localPdo(), $snapshot);
$before = array_column(localTypedRows('spots'), null, 'id');
$protectedHashes = [];
foreach (['veedels', 'park_areas', 'place_destination_reviews', 'place_reconciliations', 'media_assets', 'media_attachments'] as $table) {
    $protectedHashes[$table] = localHash(localTypedRows($table));
}
$sequences = [];
foreach (array_keys($snapshot['tables']) as $table) {
    $name = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", [$table])->name;
    if ($name !== null) {
        localCheck((bool) preg_match('/^public\.[a-z_]+$/', $name), 'Unexpected sequence.');
        $state = DB::selectOne('SELECT last_value,is_called FROM '.$name);
        $sequences[$name] = [$state->last_value, $state->is_called];
    }
}
$handle = fopen($temporary, 'x');
$success = $expectedFailure = false;
$report = [];
DB::beginTransaction();
try {
    [$prepared, $held] = namedPreparedRecords($read($output.'/selection.json'), $read($output.'/source-proof.json'), $before, $read($output.'/type-map.json'));
    localCheck(preparedPlaceRowFingerprint(['records' => $prepared, 'held' => $held]) === preparedPlaceRowFingerprint($read($output.'/native-preparation.json')), 'Native source preparation changed.');
    [$oldPrepared, $oldHeld] = heldPreparedRecords($oldHeldSelection, $oldHeldProof, $before);
    localCheck(count($oldPrepared) === 600 && $oldHeld === [], 'Previous qualified source cohort differs.');
    applyPreparedPlaces($oldAdditions, $before, $oldManifest['records_sha256']);
    applyPreparedPlaces($oldPrepared, array_column(localTypedRows('spots'), null, 'id'), hash_file('sha256', $private.'/held-public-facilities/native-preparation.json'));
    foreach ($oldPrepared as $index => $record) {
        heldAssertFacts($record);
        $preview = $review->preview($record['existing_id'], ['activity_discovery' => true]);
        $review->apply($record['existing_id'], ['activity_discovery' => true], $preview['fingerprint'], 'Current frozen OSM proof and complete geometry preserved; replay the previous local-only qualification. '.$record['source_url'], 'local-10k-facility-review');
        if (($index + 1) % 100 === 0) {
            echo json_encode(['phase' => 'previous_facility_qualification', 'verified' => $index + 1, 'total' => 600]).PHP_EOL;
        }
    }
    foreach ($oldRecords as &$record) {
        $record['fingerprint'] = $review->preview($record['spot_id'], ['activity_discovery' => true])['fingerprint'];
    }
    unset($record);
    $journal = new FacilityQualificationJournal;
    $context = ['database' => 'exp69_local_catalogue_20261001', 'package_sha256' => FacilityQualificationJournal::hash($oldRecords), 'application_sha256' => FacilityQualificationJournal::hash($runtime), 'importer_sha256' => hash_file('sha256', __DIR__.'/../local-catalogue/FacilityQualificationJournal.php')];
    $journal->apply('72c01001-2026-4101-8000-000000000004', $context, $oldRecords, $journal->snapshot(array_column($oldRecords, 'spot_id')), 'local-10k-catalogue-review');
    $priorIds = $grouping->eligible(Spot::query(), true)->orderBy('spots.id')->pluck('spots.id')->all();
    localCheck(array_map(static fn (int $id): string => 'spot:'.$id, $priorIds) === array_keys($prior), 'Previous candidate identities were lost or added.');
    echo json_encode(['phase' => 'previous_catalogue_verified', 'candidates' => count($priorIds)]).PHP_EOL;
    $packHash = $nativeSummary['native_preparation_sha256'];
    $added = applyPreparedPlaces($failureMode ? array_slice($prepared, 0, 10) : $prepared, array_column(localTypedRows('spots'), null, 'id'), $packHash);
    if ($failureMode) {
        throw new RuntimeException('Expected forced rollback after additive import.');
    }
    $idBySource = array_column($added, 'id');
    $afterFirst = array_column(localTypedRows('spots'), null, 'id');
    $observationHash = localHash(localTypedRows('place_fact_observations'));
    $replay = $prepared;
    foreach ($replay as &$record) {
        $record['existing_id'] = $added[$record['key']]['id'];
    }
    unset($record);
    applyPreparedPlaces($replay, $afterFirst, $packHash);
    localCheck(localHash(localTypedRows('spots')) === localHash(array_values($afterFirst))
        && localHash(localTypedRows('place_fact_observations')) === $observationHash, 'Additive replay changed native state.');
    foreach ($before as $id => $row) {
        if (! in_array($id, array_column($oldPrepared, 'existing_id'), true)) {
            localCheck(preparedPlaceRowFingerprint($row) === preparedPlaceRowFingerprint($afterFirst[$id]), 'Unrelated baseline row changed.');
        }
    }
    foreach (['veedels', 'park_areas', 'place_destination_reviews', 'place_reconciliations', 'media_assets', 'media_attachments'] as $table) {
        localCheck(localHash(localTypedRows($table)) === $protectedHashes[$table], 'Protected catalogue/media relationships changed.');
    }
    localCheck($grouping->eligible(Spot::query(), true)->whereIn('spots.id', $idBySource)->count() === count($added), 'Some new source identities are not native candidates.');
    $samples = [];
    foreach ($prepared as $record) {
        $samples[$record['category']] ??= $record;
    }
    $queries = [];
    foreach ($samples as $fine => $record) {
        $id = $added[$record['key']]['id'];
        $spot = Spot::findOrFail($id);
        $coarse = $spot->category->coarse();
        foreach (array_unique([$fine, $coarse]) as $selector) {
            $candidates = $repository->candidatesFor($constraints($selector), $spot->lat, $spot->lng);
            localCheck(in_array('spot:'.$id, array_column($candidates, 'id'), true), 'Nearby Composer omitted a sampled typed venue: '.$fine);
            foreach ($candidates as $candidate) {
                localCheck(in_array($candidate->category, SpotCategory::finesForSelector($selector), true) || $candidate->type === 'event', 'Composer type selector widened unexpectedly.');
            }
        }
        $facts = app(PlaceFacts::class)->resolve($spot);
        $free = $repository->candidatesFor($constraints($fine, 'free'), $spot->lat, $spot->lng);
        localCheck(in_array('spot:'.$id, array_column($free, 'id'), true) === ($facts['fee']['value'] === 'free'), 'Strict-free Composer differs from source evidence.');
        usleep(1050000);
        $detail = localApi('/api/places/'.$id, $user)['data'];
        localCheck($detail['recommendation_status'] === 'available' && $detail['name'] === $record['name'] && $detail['category'] === $coarse, 'Native detail lost source name/type.');
        usleep(1050000);
        $list = localApi('/api/places?'.http_build_query(['activity' => $fine, 'near' => false]), $user);
        localCheck($list['meta']['total'] > 0, 'Typed Places discovery is empty.');
        $queries[] = ['category' => $fine, 'coarse' => $coarse, 'spot_id' => $id, 'fee' => $facts['fee']['value'], 'native_detail_and_type_list_verified' => true, 'nearby_composer_fine_coarse_verified' => true, 'strict_free_verified' => true];
    }
    $ids = $grouping->eligible(Spot::query(), true)->orderBy('spots.id')->pluck('spots.id')->all();
    localCheck(count($ids) === count($prior) + count($added) && count($ids) >= 10000, 'Verified catalogue did not reach 10k.');
    $request = Request::create('/api/places', 'GET');
    $request->setUserResolver(static fn () => $user);
    $categories = $coarseCounts = $veedels = $nameKinds = $fees = $access = [];
    $photos = $hours = $addresses = $websites = $phones = $entrances = $descriptions = $count = 0;
    $sources = [];
    $seen = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $spots = Spot::whereIn('id', $chunk)->with(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset'])->orderBy('id')->get();
        app(PlaceFacts::class)->attach($spots);
        $candidates = array_column($repository->byIds(array_map(static fn (int $id): string => 'spot:'.$id, $chunk), $day), null, 'id');
        foreach ($spots as $spot) {
            $spot->recommendation_available = true;
            $body = (new PlaceResource($spot))->resolve($request);
            $facts = $spot->getRelation(PlaceFacts::SNAPSHOT_RELATION);
            $key = 'spot:'.$spot->id;
            $candidate = $candidates[$key] ?? null;
            localCheck($candidate !== null && $candidate->name === $body['name'] && $candidate->placeFacts === $facts
                && $candidate->lat === $body['routing_lat'] && $candidate->lng === $body['routing_lng']
                && $facts['location']['map_point']['status'] === 'known'
                && trim($body['name']) !== '' && is_finite($candidate->lat) && is_finite($candidate->lng)
                && ($facts['fee']['value'] !== 'unknown' || $candidate->costTier === 'unknown'), 'Combined native contract differs: '.$key);
            localCheck(! isset($seen[$key]), 'Duplicate native candidate ID.');
            $seen[$key] = true;
            if ($spot->source !== null && $spot->source_id !== null) {
                $owner = $spot->source.':'.$spot->source_id;
                localCheck(! isset($sources[$owner]), 'Duplicate source owner in exported catalogue.');
                $sources[$owner] = true;
            }
            if (isset($prior[$key])) {
                $expected = $prior[$key];
                localCheck($body['name'] === $expected['name'] && $candidate->category === $expected['category']
                    && $spot->source === $expected['source']['provider'] && $spot->source_id === $expected['source']['record_id']
                    && $candidate->lat === $expected['lat'] && $candidate->lng === $expected['lng']
                    && $facts['fee']['value'] === $expected['fee'] && $body['photo_url'] === $expected['photo_url'], 'Previous candidate contract changed.');
            } else {
                localCheck($facts['name_kind'] === 'source' && $facts['conflicts'] === [] && $spot->price_range === null
                    && array_all($spot->tags ?? [], static fn (mixed $value, string $tag): bool => ! is_scalar($value) || (in_array($tag, $candidate->tags, true) && in_array((string) $value, $candidate->tags, true))), 'New venue lost source facts or gained inferred prices.');
                localCheck(app(FeasibilityFilter::class)->matchesDiscovery($constraints($candidate->category, 'free', null), $candidate) === ($facts['fee']['value'] === 'free'), 'All-record strict-free evidence differs.');
            }
            fwrite($handle, json_encode(['schema_version' => 1, 'id' => $key, 'source' => ['provider' => $spot->source, 'record_id' => $spot->source_id], 'place' => $body, 'composer' => $candidate, 'scope' => 'local_candidate_catalogue_not_deployed', 'source_currentness' => 'per_field_evidence_dates'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            foreach (['categories' => $candidate->category, 'coarseCounts' => $spot->category->coarse(), 'veedels' => $spot->veedel ?? 'unassigned', 'nameKinds' => $facts['name_kind'], 'fees' => $facts['fee']['value'], 'access' => $facts['access']['value']] as $bucket => $value) {
                ${$bucket}[$value] = (${$bucket}[$value] ?? 0) + 1;
            }
            $photos += (int) ($body['photo_url'] !== null);
            $hours += (int) ($facts['hours']['raw'] !== null);
            $addresses += (int) ($facts['contact']['address']['value'] !== null);
            $websites += (int) ($facts['contact']['website']['value'] !== null);
            $phones += (int) ($facts['contact']['phone']['value'] !== null);
            $entrances += (int) ($facts['location']['entrance_point']['status'] === 'verified');
            $descriptions += (int) ($facts['description']['value'] !== null);
            $count++;
        }
        echo json_encode(['phase' => 'native_contracts', 'verified' => $count, 'total' => count($ids)]).PHP_EOL;
    }
    localCheck($count === count($ids) && array_diff(array_keys($prior), array_keys($seen)) === [], 'Export omitted native candidates.');
    $report = ['status' => 'passed', 'checked_at' => gmdate('c'), 'combined_candidates' => $count, 'new_named_destinations' => count($added), 'previous_candidates_preserved' => count($prior), 'general_destinations' => $grouping->general(Spot::query())->count(), 'new_source_objects_held' => count($held), 'native_detail_and_composer_type_samples' => count($samples), 'query_checks' => $queries, 'by_category' => $categories, 'by_coarse' => $coarseCounts, 'by_neighbourhood' => $veedels, 'name_kinds' => $nameKinds, 'fees' => $fees, 'access' => $access, 'opening_hours' => $hours, 'addresses' => $addresses, 'websites' => $websites, 'phones' => $phones, 'descriptions' => $descriptions, 'verified_entrances' => $entrances, 'policy_publishable_hero_associations' => $photos, 'photo_coverage_percent' => round($photos / $count * 100, 3), 'new_media_approvals' => 0, 'additive_replay_identical' => true, 'all_records_place_and_composer_verified' => true, 'all_new_fee_constraints_verified' => count($added), 'source_checked_at' => $sourceSummary['checked_at'], 'selection_sha256' => $selectionSummary['selection_sha256'], 'source_proof_sha256' => $sourceSummary['source_proof_sha256'], 'runtime_manifest_sha256' => $runtimeSummary['runtime_manifest_sha256'], 'remote_changed' => false];
    $success = true;
} catch (Throwable $error) {
    if ($failureMode && $error->getMessage() === 'Expected forced rollback after additive import.') {
        $expectedFailure = true;
    } else {
        throw $error;
    }
} finally {
    DB::rollBack();
    fclose($handle);
    Cache::flush();
    foreach ($sequences as $name => [$value, $called]) {
        DB::select('SELECT setval(?::regclass, ?, ?::boolean)', [$name, $value, $called ? 'true' : 'false']);
    }
    if (! $success && is_file($temporary)) {
        unlink($temporary);
    }
}
try {
    localVerify(localPdo(), $snapshot);
    foreach ($sequences as $name => [$value, $called]) {
        $state = DB::selectOne('SELECT last_value,is_called FROM '.$name);
        localCheck($state->last_value === $value && $state->is_called === $called, 'Sequence restoration differs.');
    }
    localCheck(DB::table('place_catalogue_operations')->count() === 0, 'Local operation escaped rollback.');
    if ($failureMode) {
        localCheck($expectedFailure, 'Expected rollback test did not run.');
        $result = ['status' => 'passed', 'forced_failure_after_new_additions' => 10, 'exact_baseline_restored' => true, 'sequences_restored' => true, 'private_partial_output_removed' => ! file_exists($temporary), 'remote_changed' => false];
        file_put_contents(__DIR__.'/2026-10-01/failure-cleanup-summary.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        echo json_encode($result).PHP_EOL;
    } else {
        $report['exact_baseline_restored'] = true;
        $report['sequences_restored'] = true;
        $report['candidate_export_sha256'] = hash_file('sha256', $temporary);
        $report['candidate_export_bytes'] = filesize($temporary);
        rename($temporary, $path);
        file_put_contents(__DIR__.'/2026-10-01/rehearsal-summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        echo json_encode(array_diff_key($report, array_flip(['query_checks', 'by_neighbourhood', 'by_category']))).PHP_EOL;
    }
} finally {
    DB::select("SELECT pg_advisory_unlock(hashtext('exp69_local_catalogue_rehearsal'))");
}
