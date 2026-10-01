<?php

require __DIR__.'/bootstrap-local.php';
require __DIR__.'/../production-pack/apply-pack.php';
require __DIR__.'/FacilityQualificationJournal.php';
use App\Composer\CandidateRepository;
use App\Http\Resources\PlaceResource;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\ReviewPlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$private = getenv('PLACES_LOCAL_PRIVATE');
$read = static fn (string $name): array => json_decode(file_get_contents($private.'/'.$name), true, flags: JSON_THROW_ON_ERROR);
$manifest = $read('supported-package/manifest.json');
localCheck(hash_file('sha256', $private.'/supported-package/records.jsonl') === $manifest['records_sha256'], 'Supported package changed.');
$additions = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($private.'/supported-package/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$records = $read('facility-qualification-package-private.json');
$source = $read('facility-source-private.json');
localCheck(count($records) === 90 && CarbonImmutable::parse($source['summary']['checked_at'])->betweenIncluded(now()->subDay(), now()), 'Fresh source cohort required.');
$sourceById = array_column($source['records'], null, 'source_id');
foreach ($records as $record) {
    localCheck($record['proof']['source_response_sha256'] === $sourceById[$record['source_id']]['sha256'], 'Facility source binding differs.');
}
$user = localUser();
$grouping = app(DestinationGrouping::class);
$baseline = localSnapshot();
$before = localTypedRows('spots');
$beforeEligible = $grouping->eligible(Spot::query(), true)->count();
$sequences = [];
foreach (array_keys($baseline['tables']) as $table) {
    $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", [$table])->name;
    if ($seq !== null) {
        localCheck((bool) preg_match('/^public\.[a-z_]+$/', $seq), 'Unexpected sequence.');
        $state = DB::selectOne('SELECT last_value,is_called FROM '.$seq);
        $sequences[$seq] = [$state->last_value, $state->is_called];
    }
}
$path = $private.'/candidate-places.jsonl';
$temporary = $private.'/candidate-places.partial.jsonl';
localCheck(! file_exists($path) && ! file_exists($temporary), 'Preserve previous candidate export.');
$handle = fopen($temporary, 'x');
$success = false;
DB::beginTransaction();
try {
    applyPreparedPlaces($additions, array_column($before, null, 'id'), $manifest['records_sha256']);
    foreach ($records as &$record) {
        $record['fingerprint'] = app(ReviewPlaceFacts::class)->preview($record['spot_id'], ['activity_discovery' => true])['fingerprint'];
    }
    unset($record);
    $context = ['database' => 'exp69_local_catalogue_20261001', 'package_sha256' => FacilityQualificationJournal::hash($records),
        'application_sha256' => FacilityQualificationJournal::hash($read('facility-code-manifest.json')),
        'importer_sha256' => hash_file('sha256', __DIR__.'/FacilityQualificationJournal.php')];
    $journal = new FacilityQualificationJournal;
    $ownerIds = array_column($records, 'spot_id');
    $operation = '72c01001-2026-4101-8000-000000000002';
    $receipt = $journal->apply($operation, $context, $records, $journal->snapshot($ownerIds), 'local-catalogue-review');
    localCheck((new FacilityQualificationJournal)->apply($operation, $context, $records, $receipt['before'], 'local-catalogue-review') === $receipt, 'Combined qualification replay differs.');
    $ids = $grouping->eligible(Spot::query(), true)->orderBy('spots.id')->pluck('spots.id')->all();
    localCheck(count($ids) === $beforeEligible + count($additions) + 90, 'Combined eligibility differs.');
    $request = Request::create('/api/places', 'GET');
    $request->setUserResolver(static fn () => $user);
    $day = CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Berlin');
    $count = 0;
    $photos = 0;
    $categories = [];
    DB::statement('ANALYZE spots');
    DB::statement('ANALYZE place_fact_observations');
    foreach (array_chunk($ids, 200) as $chunk) {
        $spots = Spot::whereIn('id', $chunk)->with(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset'])->orderBy('id')->get();
        app(PlaceFacts::class)->attach($spots);
        $candidates = app(CandidateRepository::class)->byIds(array_map(static fn (int $id): string => 'spot:'.$id, $chunk), $day);
        $byId = array_column($candidates, null, 'id');
        foreach ($spots as $spot) {
            $spot->recommendation_available = true;
            $body = (new PlaceResource($spot))->resolve($request);
            $candidate = $byId['spot:'.$spot->id] ?? null;
            $facts = $spot->getRelation(PlaceFacts::SNAPSHOT_RELATION);
            localCheck($candidate !== null && $candidate->name === $body['name'] && $candidate->lat === $body['routing_lat']
                && $candidate->lng === $body['routing_lng'] && $candidate->placeFacts === $facts
                && ($facts['fee']['value'] !== 'unknown' || $candidate->costTier === 'unknown'), 'Combined native consumer mismatch.');
            fwrite($handle, json_encode(['schema_version' => 1, 'id' => 'spot:'.$spot->id,
                'source' => ['provider' => $spot->source, 'record_id' => $spot->source_id],
                'place' => $body, 'composer' => $candidate,
                'scope' => 'local_candidate_catalogue_not_deployed', 'source_currentness' => 'per_field_evidence_dates'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            $photos += (int) ($body['photo_url'] !== null);
            $categories[$spot->category->value] = ($categories[$spot->category->value] ?? 0) + 1;
            $count++;
        }
        echo json_encode(['combined_contracts_checked' => $count, 'total' => count($ids)]).PHP_EOL;
    }
    $general = $grouping->general(Spot::query())->count();
    $recovery = (new FacilityQualificationJournal)->recover($operation, $context, 'local-catalogue-recovery', 'Undo the combined local qualification rehearsal while retaining the native review audit.');
    localCheck((new FacilityQualificationJournal)->recover($operation, $context, 'local-catalogue-recovery', 'Undo the combined local qualification rehearsal while retaining the native review audit.') === $recovery, 'Combined recovery replay differs.');
    localCheck($grouping->eligible(Spot::query(), true)->count() === $beforeEligible + count($additions), 'Qualification recovery removed unrelated additions.');
    $report = ['status' => 'passed', 'checked_at' => gmdate('c'), 'stored_records_in_trial' => Spot::count(),
        'eligible_before' => $beforeEligible, 'eligible_in_combined_trial' => count($ids), 'general_destinations_in_trial' => $general,
        'new_source_destinations' => count($additions), 'qualified_activity_facilities' => 90,
        'all_eligible_resource_and_composer_contracts_verified' => $count, 'by_category' => $categories,
        'policy_publishable_hero_associations' => $photos, 'photo_urls_freshly_revalidated' => false,
        'facility_apply_and_recovery_replay_identical' => true, 'source_additions_preserved_during_facility_recovery' => true,
        'remote_changed' => false, 'persistent_qualifications_added' => 0, 'source_checked_at' => $source['summary']['checked_at']];
    $success = true;
} finally {
    DB::rollBack();
    fclose($handle);
    foreach ($sequences as $seq => [$value,$called]) {
        DB::select('SELECT setval(?::regclass, ?, ?::boolean)', [$seq, $value, $called ? 'true' : 'false']);
    }
    if (! $success) {
        unlink($temporary);
    }
}
localVerify(localPdo(), $baseline);
localCheck(DB::table('place_catalogue_operations')->count() === 0, 'Trial journal escaped rollback.');
$report['exact_baseline_restore_verified'] = true;
$report['candidate_export_sha256'] = hash_file('sha256', $temporary);
$report['exported_native_candidates'] = $count;
rename($temporary, $path);
localSummary('combined-rehearsal-summary.json', $report);
