<?php

// A transaction-only acceptance rehearsal against the isolated, public-data clone.
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Enums\LocationSource;
use App\Models\Spot;
use App\Models\User;
use App\Places\PlaceFacts;
use App\Places\ReviewPlaceFacts;
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
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);
    exit(1);
});
function requireReady(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException($reason);
    }
}
function readinessDigest(string $table): string
{
    $rows = DB::select('SELECT row_to_json(t)::text AS row FROM '.$table.' t ORDER BY id');

    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}
function readinessApi(string $uri, string $method, array $data, User $user): array
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($user);
    $request = Request::create($uri, $method, $data, server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_HOST' => config('app.app_domain'), 'HTTPS' => 'on']);
    $request->setUserResolver(static fn () => $user);
    $start = hrtime(true);
    $response = app(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    requireReady($response->getStatusCode() === 200, 'API failed: '.$uri.' '.$response->getStatusCode().' '.substr($response->getContent(), 0, 200));

    return ['body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR), 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 1)];
}
requireReady(app()->environment('testing') && DB::selectOne('SELECT current_database() AS name')->name === 'exp72_ready_20260928', 'Only the dedicated public-data rehearsal database is allowed.');
requireReady(DB::table('users')->count() === 0, 'No real users may be present in this rehearsal.');
$root = getenv('PLACES_PACK_ROOT');
requireReady(is_string($root) && $root !== '', 'Specify the frozen package root.');
$pack = $root.'/docs/places/production-pack/2026-09-28';
require $root.'/docs/places/production-pack/apply-pack.php';
$manifest = json_decode(file_get_contents($pack.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
requireReady(hash_file('sha256', $pack.'/records.jsonl') === $manifest['records_sha256'], 'The frozen data checksum changed.');
$records = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($pack.'/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$baselinePath = $root.'/storage/app/private/places-research/production-pack-2026-09-28/places-only.json';
requireReady(hash_file('sha256', $baselinePath) === $manifest['baseline_sha256'], 'The frozen baseline checksum changed.');
$baseline = json_decode(file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array', 'services.composer_llm.enabled' => false]);
Http::preventStrayRequests();
Http::fake();
Queue::fake();
$app->instance(UserLocationService::class, new class extends UserLocationService
{
    public function context(User $user, ?Request $request = null, ?string $fallbackArea = null): LocationContext
    {
        return $request?->filled(['lat', 'lng'])
            ? new LocationContext((float) $request->input('lat'), (float) $request->input('lng'), LocationSource::Live, 'Rehearsal origin')
            : new LocationContext(null, null, LocationSource::None);
    }
});
$protected = [];
foreach (['spots', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_destination_reviews', 'place_reconciliations', 'media_assets', 'media_attachments', 'users', 'reviews', 'spot_feedback', 'spot_checkins'] as $table) {
    if (Schema::hasTable($table)) {
        $protected[$table] = readinessDigest($table);
    }
}
$report = ['verified_at' => gmdate('c'), 'environment' => 'isolated local transaction', 'staging_changed' => false, 'production_changed' => false, 'records_sha256' => $manifest['records_sha256']];
DB::beginTransaction();
try {
    DB::statement('SET LOCAL statement_timeout = 120000');
    $mapping = applyPreparedPlaces($records, array_column($baseline['tables']['spots'], null, 'id'), $manifest['records_sha256']);
    $ids = array_column($mapping, 'id');
    $qualified = [];
    $activityCounts = [];
    $factChecks = 0;
    $day = CarbonImmutable::parse('2026-09-29 12:00', 'Europe/Berlin');
    $football = new Constraints($day, $day->addHours(7), budget: 'free', activities: ['soccer']);
    foreach (array_chunk($ids, 200) as $chunk) {
        $spots = Spot::whereIn('id', $chunk)->get();
        $facts = app(PlaceFacts::class)->resolveMany($spots);
        foreach ($spots as $spot) {
            $f = $facts[$spot->id];
            requireReady(is_string($f['name']['value']) && trim($f['name']['value']) !== '' && $f['location']['map_point']['status'] === 'known', 'Invalid consumer name or coordinates: '.$spot->id);
            requireReady(isset($f['practical'], $f['activities'], $f['fee']['status'], $f['hours']['status']), 'Incomplete shared fact representation: '.$spot->id);
            foreach ($f['activities'] as $activity) {
                $activityCounts[$activity] = ($activityCounts[$activity] ?? 0) + 1;
            }
            $factChecks++;
            if (! $spot->is_recommendable && $f['name_kind'] === 'descriptive' && $f['activities'] !== []
                && $f['access']['value'] === 'public' && $f['access']['status'] === 'known' && $f['access']['conditional'] === null
                && $f['conflicts'] === [] && $f['practical']['sport']['source_url'] !== null) {
                $review = app(ReviewPlaceFacts::class);
                $changes = ['activity_discovery' => true];
                $preview = $review->preview($spot->id, $changes);
                $evidence = 'Prepared record has checked identity, city geometry, a descriptive label and explicit public sports evidence: '.$f['practical']['sport']['source_url'];
                $review->apply($spot->id, $changes, $preview['fingerprint'], $evidence, 'EXP-69-rehearsal-source-policy');
                $qualified[] = ['source' => $spot->source, 'source_id' => $spot->source_id, 'name' => $f['name']['value'], 'activities' => $f['activities'], 'source_url' => $f['practical']['sport']['source_url'], 'fingerprint' => $preview['fingerprint']];
            }
        }
    }
    $report['prepared_records_checked'] = $factChecks;
    $report['proposed_facility_qualifications'] = $qualified;
    $report['prepared_activity_counts'] = $activityCounts;
    $preparedCandidates = 0;
    $preparedFreeFootball = 0;
    $preparedPublicFootball = 0;
    $publicFootball = new Constraints($day, $day->addHours(7), activities: ['soccer']);
    foreach (array_chunk($ids, 200) as $chunk) {
        $candidates = app(CandidateRepository::class)->byIds(array_map(static fn ($id) => 'spot:'.$id, $chunk), $day);
        $preparedCandidates += count($candidates);
        foreach ($candidates as $candidate) {
            requireReady(isset($candidate->placeFacts['practical']), 'Composer lost practical facts.');
            $preparedFreeFootball += (int) app(FeasibilityFilter::class)->matchesDiscovery($football, $candidate);
            $preparedPublicFootball += (int) app(FeasibilityFilter::class)->matchesDiscovery($publicFootball, $candidate);
        }
    }
    $report['prepared_composer_identities'] = $preparedCandidates;
    $report['prepared_verified_free_public_football'] = $preparedFreeFootball;
    $report['prepared_known_public_football_without_free_filter'] = $preparedPublicFootball;
    $user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld', 'situation' => 'student', 'is_eu' => true]);
    $report['full_api'] = [];
    foreach (['Ehrenfeld' => [50.9485, 6.9230], 'Innenstadt' => [50.9375, 6.9603], 'Chorweiler' => [51.025, 6.899]] as $area => [$lat, $lng]) {
        $constraints = [...$football->toArray(), 'radius_km' => 3];
        $result = readinessApi('/composer/compose', 'POST', ['constraints' => $constraints, 'lat' => $lat, 'lng' => $lng], $user);
        $slots = $result['body']['plan']['slots'];
        foreach ($slots as $slot) {
            requireReady($slot['cost_tier'] === 'free' && in_array('soccer', $slot['place_facts']['activities'], true) && $slot['distance_km_from_origin'] <= 3, 'A football API result violated explicit constraints.');
            $detail = readinessApi('/api/places/'.substr($slot['id'], 5), 'GET', [], $user)['body']['data'];
            requireReady($detail['place_facts']['practical'] === $slot['place_facts']['practical'] && $detail['place_facts']['fee'] === $slot['place_facts']['fee'], 'Places/Composer fact mismatch.');
        }
        $report['full_api'][$area] = ['slots' => array_map(static fn ($slot) => ['id' => $slot['id'], 'name' => $slot['name'], 'distance_km' => $slot['distance_km_from_origin']], $slots), 'elapsed_ms' => $result['elapsed_ms'], 'no_match_is_coverage_gap' => $slots === []];
        echo json_encode(['api' => $area, 'slots' => count($slots), 'elapsed_ms' => $result['elapsed_ms']]).PHP_EOL;
    }
    $start = hrtime(true);
    $generic = app(CandidateRepository::class)->candidatesFor(new Constraints($day, $day->addHours(7)), 50.9485, 6.9230);
    $report['broad_retrieval'] = ['candidates' => count($generic), 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 1), 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1)];
} finally {
    DB::rollBack();
}
foreach ($protected as $table => $digest) {
    requireReady(readinessDigest($table) === $digest, 'Rollback changed '.$table);
}
$report['rollback_verified'] = true;
$report['code_sha256'] = [];
foreach (['app/Composer/CandidateRepository.php', 'app/Composer/PlaceSearchHints.php', 'app/Places/PlaceFacts.php', 'app/Places/PlaceCapabilities.php', 'app/Composer/FeasibilityFilter.php', 'app/Http/Controllers/ComposerController.php'] as $file) {
    $report['code_sha256'][$file] = hash_file('sha256', getcwd().'/'.$file);
}
$report['memory_note'] = 'Peak memory includes the loaded frozen package and baseline, not an isolated HTTP request.';
$report['frozen_package_unchanged'] = hash_file('sha256', $pack.'/records.jsonl') === $manifest['records_sha256'];
file_put_contents(__DIR__.'/2026-09-28/package-acceptance.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode(['finished' => true, 'prepared_checked' => $factChecks, 'qualified_facilities' => count($qualified), 'composer_identities' => $preparedCandidates, 'free_public_football' => $preparedFreeFootball, 'rollback_verified' => true]).PHP_EOL;
