<?php

// Reviews run only inside an outer transaction on the isolated public-data copy.
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Enums\LocationSource;
use App\Models\Spot;
use App\Models\User;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use App\Places\ReviewPlaceFacts;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL.$error->getTraceAsString().PHP_EOL);
    exit(1);
});

function footballReady(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException($reason);
    }
}

function footballDigests(): array
{
    $result = [];
    foreach (['spots', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_reconciliations', 'place_destination_reviews', 'media_assets', 'media_attachments', 'reviews', 'spot_feedback', 'spot_checkins', 'users'] as $table) {
        if (Schema::hasTable($table)) {
            $result[$table] = hash('sha256', json_encode(DB::select('SELECT row_to_json(t)::text AS raw FROM '.$table.' t ORDER BY id'), JSON_THROW_ON_ERROR));
        }
    }

    return $result;
}

function footballApi(string $uri, string $method, array $data, User $user): array
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    $request = Request::create($uri, $method, $data, server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_HOST' => config('app.app_domain'), 'HTTPS' => 'on']);
    $request->setUserResolver(static fn () => $user);
    $start = hrtime(true);
    $response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    footballReady($response->getStatusCode() === 200, 'API failed: '.$uri.' '.$response->getStatusCode().' '.substr($response->getContent(), 0, 200));

    return ['body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR), 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 1)];
}

footballReady(app()->environment('testing') && DB::selectOne('SELECT current_database() AS name')->name === 'exp72_ready_20260928', 'Only the dedicated local rehearsal database is allowed.');
footballReady(DB::table('users')->count() === 0, 'No private users may be present.');
$root = getenv('PLACES_PACK_ROOT');
footballReady(is_string($root) && $root !== '', 'The frozen evidence root is required.');
require $root.'/docs/places/production-pack/apply-pack.php';
$pack = $root.'/docs/places/production-pack/2026-09-28';
$manifest = json_decode(file_get_contents($pack.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$baselinePath = $root.'/storage/app/private/places-research/production-pack-2026-09-28/places-only.json';
footballReady(hash_file('sha256', $baselinePath) === $manifest['baseline_sha256'], 'Frozen baseline changed.');
footballReady(hash_file('sha256', $pack.'/records.jsonl') === $manifest['records_sha256'], 'Frozen package changed.');
$baseline = json_decode(file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
$records = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($pack.'/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$evidencePath = __DIR__.'/2026-09-28/football-source-evidence.json';
$evidence = json_decode(file_get_contents($evidencePath), true, flags: JSON_THROW_ON_ERROR);
$proposals = array_values(array_filter($evidence['records'], static fn ($row) => isset($row['proposed_source_id'], $row['existing_id'])
    && ($row['provider_approved_for_catalogue'] ?? false) === true
    && ($row['public_access_status'] ?? 'unknown') === 'known'
    && is_string($row['public_access_basis'] ?? null) && trim($row['public_access_basis']) !== ''
    && filter_var($row['public_access_evidence_url'] ?? '', FILTER_VALIDATE_URL)));
if ($proposals === []) {
    $report = ['verified_at' => gmdate('c'), 'status' => 'held_at_source_policy_preflight', 'sources_checked' => count($evidence['records']), 'accepted_fact_reviews' => 0, 'native_mutations_attempted' => false, 'api_coverage_claimed' => false, 'staging_changed' => false, 'production_changed' => false, 'reason' => 'Excluded/unreviewed providers and missing independent public-access evidence cannot authorize fact corrections or activity qualification.'];
    file_put_contents(__DIR__.'/2026-09-28/football-rehearsal.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array', 'services.composer_llm.enabled' => false]);
Http::preventStrayRequests();
Http::fake();
Queue::fake();
$app->instance(UserLocationService::class, new class extends UserLocationService
{
    public function context(User $user, ?Request $request = null, ?string $fallbackArea = null): LocationContext
    {
        return $request?->filled(['lat', 'lng'])
            ? new LocationContext((float) $request->input('lat'), (float) $request->input('lng'), LocationSource::Live, 'Public-source rehearsal origin')
            : new LocationContext(null, null, LocationSource::None);
    }
});
$before = footballDigests();
$report = ['verified_at' => gmdate('c'), 'environment' => 'isolated local transaction', 'staging_changed' => false, 'production_changed' => false, 'evidence_sha256' => hash_file('sha256', $evidencePath), 'prepared_package_sha256' => $manifest['records_sha256'], 'reviews' => [], 'full_api' => [], 'promotion_status' => 'held pending live reconciliation and unresolved legacy identity review'];
DB::beginTransaction();
try {
    DB::statement('SET LOCAL statement_timeout = 120000');
    DB::statement('SET LOCAL lock_timeout = 5000');
    $mapping = applyPreparedPlaces($records, array_column($baseline['tables']['spots'], null, 'id'), $manifest['records_sha256']);
    $report['prepared_package_records'] = count($mapping);
    $service = app(ReviewPlaceFacts::class);
    foreach ($proposals as $proposal) {
        footballReady($proposal['fee_reported_free'] && $proposal['audience_all_ages'] && $proposal['description_confirms_football'] && $proposal['description_confirms_goals'], 'Source evidence does not establish the required facts.');
        $spot = Spot::findOrFail($proposal['existing_id']);
        footballReady($spot->source === 'osm' && $spot->source_id === $proposal['proposed_source_id'] && $spot->is_active && $spot->canonical_spot_id === null, 'Source identity differs from the reviewed proposal.');
        $factsBefore = app(PlaceFacts::class)->resolve($spot);
        footballReady(in_array('soccer', $factsBefore['activities'], true), 'OSM no longer establishes football at this identity.');
        footballReady(! in_array($factsBefore['access']['value'], ['private', 'members', 'customers', 'permit'], true) && $factsBefore['conflicts'] === [], 'A conflicting source needs separate review.');
        foreach (['fee' => 'free', 'access' => 'public'] as $field => $value) {
            $changes = [$field => $value];
            $preview = $service->preview($spot->id, $changes);
            $service->apply($spot->id, $changes, $preview['fingerprint'], $field === 'access' ? $proposal['public_access_evidence_url'] : $proposal['url'], 'EXP-69-source-evidence-rehearsal');
        }
        $qualified = ! $spot->is_recommendable;
        if ($qualified) {
            $review = ['activity_discovery' => true];
            $preview = $service->preview($spot->id, $review);
            $service->apply($spot->id, $review, $preview['fingerprint'], $proposal['url'], 'EXP-69-source-evidence-rehearsal');
        }
        $facts = app(PlaceFacts::class)->resolve($spot->fresh());
        footballReady($facts['fee']['value'] === 'free' && $facts['fee']['status'] === 'known' && $facts['fee']['source_url'] === $proposal['url'], 'Reviewed fee or evidence was lost.');
        footballReady($facts['access']['value'] === 'public' && $facts['access']['status'] === 'known' && $facts['access']['source_url'] === $proposal['public_access_evidence_url'], 'Reviewed public access or evidence was lost.');
        foreach (['location', 'hours', 'practical'] as $field) {
            footballReady($facts[$field] === $factsBefore[$field], 'A limited fact review altered '.$field);
        }
        $report['reviews'][] = ['spot_id' => $spot->id, 'source_id' => $spot->source_id, 'name' => $facts['name']['value'], 'evidence_url' => $proposal['url'], 'activity_qualification_rehearsed' => $qualified, 'map_point' => $facts['location']['map_point'], 'hours_status' => $facts['hours']['status'], 'practical_booking_status' => $facts['practical']['booking']['status']];
    }
    $afterReviews = footballDigests();
    foreach ($proposals as $proposal) {
        foreach (['fee' => 'free', 'access' => 'public'] as $field => $value) {
            $changes = [$field => $value];
            $preview = $service->preview($proposal['existing_id'], $changes);
            $service->apply($proposal['existing_id'], $changes, $preview['fingerprint'], $field === 'access' ? $proposal['public_access_evidence_url'] : $proposal['url'], 'EXP-69-source-evidence-rehearsal');
        }
    }
    footballReady(footballDigests() === $afterReviews, 'Identical reviewed facts were not idempotent.');
    $day = CarbonImmutable::parse('2026-09-29 12:00', 'Europe/Berlin');
    $constraints = new Constraints($day, $day->addHours(7), budget: 'free', activities: ['soccer'], radiusKm: 3);
    $user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld', 'situation' => 'student', 'is_eu' => true]);
    foreach ($proposals as $proposal) {
        $spot = Spot::findOrFail($proposal['existing_id']);
        $origin = ['lat' => $spot->lat, 'lng' => $spot->lng];
        $candidates = app(CandidateRepository::class)->candidatesFor($constraints, $spot->lat, $spot->lng);
        footballReady(collect($candidates)->contains(fn ($candidate) => $candidate->id === 'spot:'.$spot->id), 'The reviewed place is missing from its nearby free-football retrieval.');
        $result = footballApi('/composer/compose', 'POST', ['constraints' => $constraints->toArray(), ...$origin], $user);
        $slots = $result['body']['plan']['slots'];
        footballReady($slots !== [], 'The reviewed free-football origin still has no plan result.');
        foreach ($slots as $slot) {
            footballReady($slot['cost_tier'] === 'free' && in_array('soccer', $slot['place_facts']['activities'], true) && $slot['distance_km_from_origin'] <= 3, 'An API result violated a hard constraint.');
            footballReady($slot['place_facts']['access']['value'] === 'public' && $slot['place_facts']['access']['status'] === 'known', 'An API result lost public-access evidence.');
            $detail = footballApi('/api/places/'.substr($slot['id'], 5), 'GET', [], $user)['body']['data'];
            foreach (['fee', 'access', 'practical', 'location'] as $field) {
                footballReady($detail['place_facts'][$field] === $slot['place_facts'][$field], 'Places and Composer disagree about '.$field);
            }
        }
        $report['full_api'][] = ['origin_spot_id' => $spot->id, 'source_title' => $proposal['title'], 'retrieved_candidates' => count($candidates), 'slots' => array_map(static fn ($slot) => ['id' => $slot['id'], 'name' => $slot['name'], 'distance_km' => $slot['distance_km_from_origin']], $slots), 'elapsed_ms' => $result['elapsed_ms']];
        echo json_encode(['origin' => $spot->id, 'api_slots' => count($slots), 'elapsed_ms' => $result['elapsed_ms']]).PHP_EOL;
    }
    $restricted = Spot::findOrFail($proposals[0]['existing_id']);
    app(RecordPlaceObservation::class)->record($restricted, ['provider' => 'osm', 'provider_record_id' => $restricted->source_id, 'source_url' => 'https://www.openstreetmap.org/'.$restricted->source_id, 'observed_at' => now()->addMinute(), 'ingestion_key' => 'EXP-69-rehearsal-restriction', 'payload' => ['access' => ['raw' => 'private']]]);
    Cache::flush();
    footballReady(! Spot::query()->recommendationEligible(true)->whereKey($restricted->id)->exists(), 'A newer restriction did not suspend the older public-access review.');
    $candidates = app(CandidateRepository::class)->byIds(['spot:'.$restricted->id], $day);
    footballReady($candidates === [] || ! app(FeasibilityFilter::class)->matchesDiscovery($constraints, $candidates[0]), 'Composer ignored a newer access restriction.');
    $after = footballDigests();
    foreach (['media_assets', 'media_attachments', 'reviews', 'spot_feedback', 'spot_checkins'] as $table) {
        footballReady(($after[$table] ?? null) === ($before[$table] ?? null), 'Unrelated media or user data changed: '.$table);
    }
    $report['checks'] = ['identical_reviews_idempotent' => true, 'all_reviewed_origins_return_candidates' => true, 'hard_filters_preserved' => true, 'places_and_composer_agree' => true, 'unknown_hours_and_booking_preserved' => true, 'coordinates_preserved' => true, 'newer_restriction_fails_closed' => true, 'no_media_modified' => true];
} finally {
    DB::rollBack();
}
footballReady(footballDigests() === $before, 'Rollback did not restore the exact protected table contents.');
$report['checks']['rollback_exact'] = true;
footballReady(hash_file('sha256', $pack.'/records.jsonl') === $manifest['records_sha256'], 'The frozen package changed.');
file_put_contents(__DIR__.'/2026-09-28/football-rehearsal.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode(['prepared_records' => count($mapping), 'reviewed_venues' => count($report['reviews']), 'api_origins' => count($report['full_api']), 'checks' => $report['checks']], JSON_THROW_ON_ERROR).PHP_EOL;
