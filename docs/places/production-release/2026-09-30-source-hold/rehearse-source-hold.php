<?php

use App\Models\Spot;
use App\Places\DestinationGrouping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap-lab.php';
labBoot();
require getcwd().'/docs/places/production-release/SourceProvenanceHoldJournal.php';
$runtime = json_decode(file_get_contents(__DIR__.'/source-hold-runtime-fingerprint.json'), true, flags: JSON_THROW_ON_ERROR);
labCheck(SourceProvenanceHoldJournal::applicationHash() === $runtime['application_sha256'], 'Wrong candidate runtime');
labCheck(hash_file('sha256', getcwd().'/docs/places/production-release/SourceProvenanceHoldJournal.php') === $runtime['importer_sha256'], 'Wrong hold helper');
$tables = ['spots', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_reconciliations',
    'place_destination_reviews', 'place_catalogue_operations', 'park_areas', 'venues', 'media_assets', 'media_attachments', 'reviews', 'spot_feedback', 'users'];
$digests = static function () use ($tables): array {
    $out = [];
    foreach ($tables as $table) {
        $row = DB::selectOne('SELECT count(*) AS rows, md5(COALESCE(string_agg(md5(to_jsonb(t)::text), \'\' ORDER BY id), \'\')) AS digest FROM '.$table.' t');
        $out[$table] = ['rows' => (int) $row->rows, 'sha256_of_ordered_row_digests' => hash('sha256', $row->digest)];
    }

    return $out;
};
$beforeTables = $digests();
$old = DB::table('place_catalogue_operations')->where('id', '69eab930-2026-4930-8000-000000000001')->first();
labCheck($old !== null && $old->state === 'applied', 'Expected existing isolated source hold');
$oldReceipt = json_decode($old->receipt, true, flags: JSON_THROW_ON_ERROR);
labCheck(count($oldReceipt['before']) === 4680, 'Earlier hold cohort drift');
$reason = 'Rehearse reversible provenance exclusion against the complete isolated pre-hold cohort.';
$stats = [];
DB::beginTransaction();
try {
    // Restore ONLY earlier owned flags/time within this rollback-only rehearsal.
    foreach ($oldReceipt['before'] as $row) {
        labCheck($row['source'] === null && DB::table('spots')->where('id', $row['id'])->whereNull('source')->exists(), 'Source-null scope drift');
        DB::table('spots')->where('id', $row['id'])->update(['is_active' => $row['is_active'], 'is_recommendable' => $row['is_recommendable'], 'updated_at' => $row['updated_at']]);
    }
    $journal = new SourceProvenanceHoldJournal;
    $baseline = $journal->snapshot();
    labCheck(array_column($baseline['spots'], 'id') === array_column($oldReceipt['before'], 'id'), 'Complete cohort IDs differ');
    $context = ['database' => $labDatabase, 'package_sha256' => SourceProvenanceHoldJournal::hash($baseline), ...$runtime];
    $id = (string) Str::uuid();
    $generalBefore = app(DestinationGrouping::class)->general(Spot::query())->orderBy('spots.id')->pluck('spots.id')->all();
    $unknownBefore = app(DestinationGrouping::class)->eligible(Spot::query(), true)->whereNull('source')->count();
    $receipt = $journal->apply($id, $context, $baseline, 'source-hold-isolated-rehearsal', $reason);
    labCheck((new SourceProvenanceHoldJournal)->apply($id, $context, $baseline, 'source-hold-isolated-rehearsal', $reason) === $receipt, 'New-instance apply replay differs');
    $generalHeld = app(DestinationGrouping::class)->general(Spot::query())->count();
    labCheck(app(DestinationGrouping::class)->eligible(Spot::query(), true)->whereNull('source')->count() === 0, 'Unknown provenance remains eligible');
    $recovery = (new SourceProvenanceHoldJournal)->recover($id, $context, 'source-hold-isolated-rehearsal', $reason);
    labCheck((new SourceProvenanceHoldJournal)->recover($id, $context, 'source-hold-isolated-rehearsal', $reason) === $recovery, 'New-instance recovery replay differs');
    labCheck($journal->snapshot() === $baseline, 'Original owner flags fields or audit hashes changed');
    labCheck(app(DestinationGrouping::class)->general(Spot::query())->orderBy('spots.id')->pluck('spots.id')->all() === $generalBefore, 'General discovery not restored');
    $stats = ['source_null_records' => count($baseline['spots']), 'unknown_eligible_before' => $unknownBefore,
        'unknown_eligible_after' => 0, 'general_before' => count($generalBefore), 'general_held' => $generalHeld,
        'apply_replay_passed' => true, 'recovery_replay_passed' => true, 'owned_snapshot_restored' => true,
        'general_discovery_restored' => true, 'baseline_sha256' => $context['package_sha256'], 'receipt_sha256' => $receipt['sha256'],
        'recovery_sha256' => $recovery['sha256']];
    file_put_contents(__DIR__.'/source-hold-rehearsal-private.json', json_encode(['context' => $context, 'receipt' => $receipt, 'recovery' => $recovery], JSON_THROW_ON_ERROR));
} finally {
    DB::rollBack();
}
labCheck($digests() === $beforeTables, 'Outer rollback did not restore all protected tables');
$summary = ['status' => 'passed', ...$stats, ...$runtime, 'protected_tables_restored' => count($tables),
    'persistent_lab_mutation' => false, 'staging_changed' => false, 'production_changed' => false,
    'raw_rows_exported' => false, 'real_user_rows_returned' => false, 'committed_process_restart_drill' => false, 'checked_at' => gmdate('c')];
file_put_contents(__DIR__.'/source-hold-rehearsal-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL;
