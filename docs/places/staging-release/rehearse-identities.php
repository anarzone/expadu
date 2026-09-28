<?php

use App\Models\Spot;
use App\Places\PlaceIdentity;
use App\Places\ReconcilePlace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error::class.': '.$error->getMessage().PHP_EOL);
    exit(1);
});

function identityReady(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function identityTableDigests(): array
{
    $result = [];
    foreach (['spots', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_reconciliations', 'place_destination_reviews', 'media_assets', 'media_attachments', 'reviews', 'spot_feedback', 'spot_checkins', 'venues', 'park_areas', 'users'] as $table) {
        if (! Schema::hasTable($table)) {
            $result[$table] = null;

            continue;
        }
        $rows = DB::select('SELECT row_to_json(t)::text AS raw FROM '.$table.' t ORDER BY id');
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }

    return $result;
}

identityReady(app()->environment('testing') && DB::selectOne('SELECT current_database() AS name')->name === 'exp72_ready_20260928', 'Only the dedicated local rehearsal database is allowed.');
identityReady(DB::table('users')->count() === 0, 'Private users are not allowed in this rehearsal.');
$root = getenv('PLACES_PACK_ROOT');
identityReady(is_string($root) && $root !== '', 'Frozen evidence root is required.');
require $root.'/docs/places/production-pack/apply-pack.php';
$inputPath = __DIR__.'/2026-09-28/identity-proposals.json';
$input = json_decode(file_get_contents($inputPath), true, flags: JSON_THROW_ON_ERROR);
$safeSubset = in_array('--safe-subset', $argv, true);
if ($safeSubset) {
    $input['operations'] = array_values(array_filter($input['operations'], static fn ($op) => $op['legacy_recommendable'] && $op['canonical_recommendable']));
    identityReady(count($input['operations']) === 11, 'The separately reviewed subset changed.');
}
$baselinePath = $root.'/storage/app/private/places-research/production-pack-2026-09-28/places-only.json';
identityReady(hash_file('sha256', $baselinePath) === $input['summary']['baseline_sha256'], 'Frozen baseline changed.');
$baseline = array_column(json_decode(file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR)['tables']['spots'], null, 'id');
config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array']);
Http::preventStrayRequests();
Queue::fake();
$before = identityTableDigests();
$eligibleBefore = Spot::query()->recommendationEligible(true)->pluck('id')->map(fn ($id) => (int) $id)->all();
$generalBefore = Spot::query()->recommendationEligible()->pluck('id')->map(fn ($id) => (int) $id)->all();
$report = ['checked_at' => gmdate('c'), 'environment' => 'isolated local transaction', 'input_sha256' => hash_file('sha256', $inputPath), 'scope' => $safeSubset ? 'separate subset retaining both general and activity visibility' : 'all strict identity proposals', 'live_reconciliation_required' => true, 'proposals' => count($input['operations']), 'applied_in_rehearsal' => [], 'policy_holds' => [], 'staging_changed' => false, 'production_changed' => false];
DB::beginTransaction();
try {
    DB::statement('SET LOCAL statement_timeout = 120000');
    DB::statement('SET LOCAL lock_timeout = 5000');
    $service = app(ReconcilePlace::class);
    foreach ($input['operations'] as $op) {
        foreach ([$op['alias_id'], $op['canonical_id']] as $id) {
            $current = json_decode(DB::selectOne('SELECT row_to_json(s)::text AS raw FROM spots s WHERE id = ?', [$id])->raw, true, flags: JSON_THROW_ON_ERROR);
            identityReady(preparedPlaceRowFingerprint($current) === preparedPlaceRowFingerprint($baseline[$id]), 'Pair differs from frozen baseline: '.$id);
        }
        try {
            $preview = $service->preview($op['alias_id'], $op['canonical_id']);
            $service->apply($op['alias_id'], $op['canonical_id'], $preview['fingerprint'], $op['evidence'].' Source: '.$op['source_url']);
            $report['applied_in_rehearsal'][] = ['alias_id' => $op['alias_id'], 'canonical_id' => $op['canonical_id'], 'source_id' => $op['source_id']];
        } catch (DomainException $error) {
            $report['policy_holds'][] = ['alias_id' => $op['alias_id'], 'canonical_id' => $op['canonical_id'], 'reason' => $error->getMessage()];
        }
    }
    $after = identityTableDigests();
    identityReady($after['spots']['count'] === $before['spots']['count'], 'An identity was deleted.');
    foreach (['media_assets', 'media_attachments', 'reviews', 'spot_feedback', 'spot_checkins', 'users'] as $table) {
        identityReady($after[$table] === $before[$table], 'Unrelated user/media data changed: '.$table);
    }
    $identity = app(PlaceIdentity::class);
    $mapped = $identity->canonicalIds(array_column($report['applied_in_rehearsal'], 'alias_id'));
    $eligibleAfter = array_fill_keys(Spot::query()->recommendationEligible(true)->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
    $generalAfter = array_fill_keys(Spot::query()->recommendationEligible()->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
    $lost = [];
    $generalLost = [];
    $beforeEligibleSet = array_fill_keys($eligibleBefore, true);
    $generalBeforeSet = array_fill_keys($generalBefore, true);
    foreach ($report['applied_in_rehearsal'] as $op) {
        identityReady($mapped[$op['alias_id']] === $op['canonical_id'], 'Wrong identity mapping.');
        identityReady((new Spot)->resolveRouteBinding($op['alias_id'])->id === $op['canonical_id'], 'Old detail link does not resolve.');
        $plan = $identity->normalizePlan(['slots' => [['id' => 'spot:'.$op['alias_id']]], 'pins' => ['spot:'.$op['alias_id']], 'locked' => ['spot:'.$op['alias_id']]]);
        identityReady($plan['slots'][0]['id'] === 'spot:'.$op['canonical_id'] && $plan['pins'][0] === 'spot:'.$op['canonical_id'] && $plan['locked'][0] === 'spot:'.$op['canonical_id'], 'Saved reference was lost.');
        if (isset($beforeEligibleSet[$op['alias_id']]) && ! isset($eligibleAfter[$op['canonical_id']])) {
            $lost[] = $op;
        }
        if (isset($generalBeforeSet[$op['alias_id']]) && ! isset($generalAfter[$op['canonical_id']])) {
            $generalLost[] = $op;
        }
        $preview = $service->preview($op['alias_id'], $op['canonical_id']);
        $service->apply($op['alias_id'], $op['canonical_id'], $preview['fingerprint'], 'Replay of identical locally reviewed public identity evidence.');
    }
    identityReady(identityTableDigests() === $after, 'Idempotent identity replay changed data.');
    $report['eligibility_losses_requiring_separate_qualification'] = $lost;
    $report['general_browse_losses'] = $generalLost;
    $report['local_visibility_checks_passed'] = $lost === [] && $generalLost === [] && $report['policy_holds'] === [];
    $report['promotion_allowed'] = false;
    $report['promotion_hold'] = 'Fresh target-environment identity reconciliation and release review are required.';
    $report['checks'] = ['old_links_resolve' => true, 'saved_references_resolve' => true, 'no_ids_deleted' => true, 'user_and_media_data_unchanged' => true, 'replay_exact' => true];
} finally {
    DB::rollBack();
}
identityReady(identityTableDigests() === $before, 'Rollback did not restore exact protected table contents.');
$report['checks']['rollback_exact'] = true;
file_put_contents(__DIR__.'/2026-09-28/'.($safeSubset ? 'identity-safe-rehearsal.json' : 'identity-rehearsal.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
if ($safeSubset && $report['local_visibility_checks_passed']) {
    $originalSummary = $input['summary'];
    $input['summary'] = array_intersect_key($originalSummary, array_flip(['baseline_sha256', 'package_records_sha256', 'staging_changed', 'production_changed']));
    $input['summary']['scope'] = 'separate 11-pair proposal verified on frozen local baseline; live reconciliation required';
    $input['summary']['selected_operations'] = count($input['operations']);
    $input['summary']['categories'] = array_count_values(array_column($input['operations'], 'category'));
    $input['summary']['promotion_allowed'] = false;
    $input['summary']['rehearsal_checks'] = $report['checks'];
    file_put_contents(__DIR__.'/2026-09-28/identity-safe-operations.json', json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
}
echo json_encode(['proposals' => $report['proposals'], 'rehearsed' => count($report['applied_in_rehearsal']), 'policy_holds' => count($report['policy_holds']), 'eligibility_losses' => count($report['eligibility_losses_requiring_separate_qualification']), 'local_visibility_checks_passed' => $report['local_visibility_checks_passed'], 'promotion_allowed' => false, 'checks' => $report['checks']], JSON_THROW_ON_ERROR).PHP_EOL;
