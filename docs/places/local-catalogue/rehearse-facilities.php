<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Enums\TransportMode;
use App\Models\Spot;
use App\Models\User;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\ReviewPlaceFacts;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap-local.php';
$labRoot = getenv('PLACES_LOCAL_PRIVATE');
$labDatabase = 'exp69_local_catalogue_20261001';
localUser();
require __DIR__.'/FacilityQualificationJournal.php';
$read = static fn (string $name): array => json_decode(file_get_contents($labRoot.'/'.$name), true, flags: JSON_THROW_ON_ERROR);
$save = static fn (string $name, array $value) => file_put_contents($labRoot.'/'.$name, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$manifest = $read('facility-code-manifest.json');
foreach ($manifest['files'] as $path => $sha) {
    localCheck(hash_file('sha256', getcwd().'/'.$path) === $sha, 'Installed candidate differs at '.$path);
}
localCheck(hash_file('sha256', __DIR__.'/FacilityQualificationJournal.php') === $manifest['facility_helper_sha256'], 'Qualification helper differs');
$target = $read('facility-target-private.json');
$source = $read('facility-source-private.json');
$sequences = [];
foreach (array_keys(localSnapshot()['tables']) as $table) {
    $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", [$table])->name;
    if ($seq !== null) {
        localCheck((bool) preg_match('/^public\.[a-z_]+$/', $seq), 'Unexpected sequence.');
        $state = DB::selectOne('SELECT last_value,is_called FROM '.$seq);
        $sequences[$seq] = [$state->last_value, $state->is_called];
    }
}
$records = [];
$held = [];
foreach ($target['records'] as $row) {
    if ($row['reasons'] !== []) {
        $held[] = $row['spot_id'];

        continue;
    }
    $spot = Spot::findOrFail($row['spot_id']);
    $current = $row['fresh']['current'];
    $proof = ['source_id' => $spot->source_id, 'visible' => $current['visible'], 'version' => (int) $current['version'],
        'tags' => $current['tags'], 'geometry_verified' => $row['geometry_check'] === null || ($row['geometry_check']['valid'] && $row['geometry_check']['representative_point_difference_m'] <= 1),
        'lat' => (float) $row['source']['lat'], 'lng' => (float) $row['source']['lng'],
        'source_response_sha256' => $row['source_proof_sha256'], 'geometry_check' => $row['geometry_check']];
    $records[] = ['source' => 'osm', 'source_id' => $spot->source_id, 'spot_id' => $spot->id, 'category' => $spot->category->value,
        'checked_at' => $source['summary']['checked_at'], 'proof' => $proof, 'proof_sha256' => FacilityQualificationJournal::hash($proof),
        'fingerprint' => app(ReviewPlaceFacts::class)->preview($spot->id, ['activity_discovery' => true])['fingerprint']];
}
localCheck(count($records) === 90 && count($held) === 469, 'Reviewed facility cohort differs');
$save('facility-qualification-package-private.json', $records);
$context = ['database' => $labDatabase, 'package_sha256' => FacilityQualificationJournal::hash($records),
    'application_sha256' => FacilityQualificationJournal::hash($manifest), 'importer_sha256' => hash_file('sha256', __DIR__.'/FacilityQualificationJournal.php')];
$service = new FacilityQualificationJournal;
$ownerIds = array_column($records, 'spot_id');
$before = $service->snapshot($ownerIds);
$all = static fn (): string => FacilityQualificationJournal::hash(['spots' => DB::table('spots')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    'observations' => DB::table('place_fact_observations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    'corrections' => DB::table('place_fact_corrections')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    'journals' => DB::table('place_catalogue_operations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()]);
$beforeHash = $all();
$user = new User;
$user->forceFill(['id' => -9223372036854770003, 'email_verified_at' => now(), 'onboarded_at' => now(), 'transport_mode' => TransportMode::Walk]);
$api = static function (string $path) use ($user): array {
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    $request = Request::create($path, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_HOST' => config('app.app_domain'), 'HTTPS' => 'on']);
    $request->setUserResolver(static fn () => $user);
    $response = app(Kernel::class)->handle($request);
    localCheck($response->getStatusCode() === 200, 'Facility API request failed with status '.$response->getStatusCode());

    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
};
$constraints = static fn (string $category, ?string $budget = null): Constraints => Constraints::fromArray([
    'window_start' => '2026-10-01T14:00:00+02:00', 'window_end' => '2026-10-01T18:00:00+02:00',
    'categories' => [$category], 'budget' => $budget, 'radius_km' => .1,
]);
$repository = app(CandidateRepository::class);
$id = '72c00930-2026-4930-8000-000000000002';
DB::beginTransaction();
try {
    $eligibleBefore = app(DestinationGrouping::class)->eligible(Spot::query(), true)->count();
    $generalBefore = app(DestinationGrouping::class)->general(Spot::query())->count();
    $receipt = $service->apply($id, $context, $records, $before, 'expadu-isolated-rehearsal');
    $save('facility-qualification-applied-private.json', $receipt);
    localCheck((new FacilityQualificationJournal)->apply($id, $context, $records, $before, 'expadu-isolated-rehearsal') === $receipt, 'Apply replay differs');
    echo json_encode(['phase' => 'qualified_trial', 'facilities' => count($records)]).PHP_EOL;
    $details = $maps = $composer = $freeExcluded = 0;
    foreach ($records as $record) {
        $spot = Spot::findOrFail($record['spot_id']);
        $facts = app(PlaceFacts::class)->resolve($spot);
        localCheck($facts['name_kind'] === 'descriptive' && $facts['fee']['value'] === 'unknown' && $facts['access']['value'] === 'public'
            && ! $spot->is_recommendable && $spot->price_range === null && $facts['location']['entrance_point']['status'] !== 'verified', 'A qualification invented a source fact');
        $body = $api('/api/places/'.$spot->id)['data'];
        localCheck($body['id'] === $spot->id && $body['recommendation_status'] === 'available' && $body['price_text'] === null
            && $body['place_facts']['fee']['value'] === 'unknown', 'Facility detail contract differs');
        $details++;
        usleep(1050000);
        $map = $api('/api/spots?'.http_build_query(['sw_lat' => $spot->lat - .001, 'ne_lat' => $spot->lat + .001,
            'sw_lng' => $spot->lng - .001, 'ne_lng' => $spot->lng + .001, 'category' => $record['category'], 'limit' => 200]));
        localCheck(in_array($spot->id, array_column($map, 'id'), true), 'Facility missing from category map request');
        $maps++;
        $candidates = $repository->candidatesFor($constraints($record['category']), $spot->lat, $spot->lng);
        localCheck(in_array('spot:'.$spot->id, array_column($candidates, 'id'), true), 'Facility missing from actual nearby Composer category request');
        $candidate = array_values(array_filter($candidates, fn ($c) => $c->id === 'spot:'.$spot->id))[0];
        localCheck($candidate->costTier === 'unknown' && $candidate->placeFacts['fee']['value'] === 'unknown', 'Composer invented a fee');
        $composer++;
        $free = $repository->candidatesFor($constraints($record['category'], 'free'), $spot->lat, $spot->lng);
        localCheck(! in_array('spot:'.$spot->id, array_column($free, 'id'), true), 'Unknown fee entered a strict free request');
        $freeExcluded++;
        if ($details % 15 === 0) {
            echo json_encode(['phase' => 'consumer_verification', 'checked' => $details, 'total' => 90]).PHP_EOL;
        }
    }
    $listed = [];
    foreach (array_unique(array_column($records, 'category')) as $category) {
        $page = 1;
        do {
            $body = $api('/api/places?'.http_build_query(['activity' => $category, 'page' => $page]));
            $listed = [...$listed, ...array_column($body['data'], 'id')];
            $page++;
        } while ($page <= $body['meta']['last_page']);
    }
    localCheck(array_diff($ownerIds, $listed) === [], 'Qualified facility missing from paginated Places category discovery');
    localCheck(app(DestinationGrouping::class)->general(Spot::query())->whereIn('spots.id', $ownerIds)->count() === 0
        && app(DestinationGrouping::class)->general(Spot::query())->count() === $generalBefore, 'Qualified facilities changed general destination discovery');
    localCheck(app(DestinationGrouping::class)->eligible(Spot::query(), true)->whereIn('spots.id', $held)->count() === 0, 'Unknown-access facility escaped its hold');
    $eligibleAfter = app(DestinationGrouping::class)->eligible(Spot::query(), true)->count();
    localCheck($eligibleAfter === $eligibleBefore + 90, 'Eligible count differs');
    $recovery = (new FacilityQualificationJournal)->recover($id, $context, 'expadu-isolated-recovery', 'Undo the isolated facility rehearsal while preserving native qualification and revocation audit.');
    $save('facility-qualification-recovered-private.json', $recovery);
    localCheck((new FacilityQualificationJournal)->recover($id, $context, 'expadu-isolated-recovery', 'Undo the isolated facility rehearsal while preserving native qualification and revocation audit.') === $recovery, 'Recovery replay differs');
    localCheck(app(DestinationGrouping::class)->eligible(Spot::query(), true)->count() === $eligibleBefore, 'Recovery failed to restore eligibility');
    foreach ($ownerIds as $spotId) {
        localCheck($api('/api/places/'.$spotId)['data']['recommendation_status'] === 'unavailable', 'Recovered facility detail remains available');
    }
    $summary = ['status' => 'passed', 'checked_at' => gmdate('c'), 'application_files_verified' => count($manifest['files']),
        'stored_records' => Spot::count(), 'source_records_checked' => 559, 'previously_prepared_held_facility_cohort' => 559,
        'qualified_facilities_in_trial' => count($records), 'categories' => $target['summary']['qualification_review_categories'],
        'unknown_access_facilities_held' => count($held), 'eligible_before' => $eligibleBefore, 'eligible_in_trial' => $eligibleAfter,
        'general_destinations_before_and_after' => $generalBefore, 'api_details_verified' => $details,
        'map_category_discovery_verified' => $maps, 'composer_nearby_category_discovery_verified' => $composer,
        'places_paginated_category_discovery_verified' => count($ownerIds), 'strict_free_exclusions_verified' => $freeExcluded,
        'qualification_reviews_added_in_trial' => 90, 'audit_entries_after_recovery_in_trial' => 180,
        'recovered_unavailable_details_verified' => count($ownerIds), 'apply_replay_identical' => true, 'recovery_replay_identical' => true,
        'recovery_from_database_journal_with_new_service_instance' => true, 'actual_process_crash_tested' => false,
        'context' => $context, 'outer_transaction_rolled_back' => true, 'production_changed' => false, 'staging_changed' => false,
        'persistent_qualifications_added' => 0, 'raw_rows_exported' => false];
} finally {
    DB::rollBack();
    Cache::flush();
    foreach ($sequences as $seq => [$value,$called]) {
        DB::select('SELECT setval(?::regclass, ?, ?::boolean)', [$seq, $value, $called ? 'true' : 'false']);
    }
}
localCheck($all() === $beforeHash, 'Outer rollback did not restore every source/place/review/journal row');
localCheck(DB::table('users')->count() === 0, 'Synthetic API user persisted');
$summary['all_source_place_review_journal_rows_restored'] = true;
localVerify(localPdo(), localSnapshot());
$summary['source_checked_at'] = $source['summary']['checked_at'];
$summary['baseline_snapshot_sha256'] = hash_file('sha256', $labRoot.'/catalogue.json');
$summary['source_proof_sha256'] = hash_file('sha256', $labRoot.'/facility-source-private.json');
$summary['exact_native_catalogue_restore_verified'] = true;
localSummary('facility-rehearsal-summary.json', $summary);
$save('facility-qualification-summary.json', $summary);
echo json_encode(['result' => $summary]).PHP_EOL;
