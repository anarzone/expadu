<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/bootstrap-lab.php';
labBoot();
require getcwd().'/docs/places/production-release/SourceProvenanceHoldJournal.php';
$runtime = json_decode(file_get_contents(__DIR__.'/source-hold-runtime-fingerprint.json'), true, flags: JSON_THROW_ON_ERROR);
labCheck(SourceProvenanceHoldJournal::applicationHash() === $runtime['application_sha256'], 'Wrong candidate runtime');
labCheck(hash_file('sha256', getcwd().'/docs/places/production-release/SourceProvenanceHoldJournal.php') === $runtime['importer_sha256'], 'Wrong hold helper');
require __DIR__.'/source-hold-readonly-connection.php';
$previous = DB::getDefaultConnection();
try {
    DB::setDefaultConnection($name);
    $journal = new SourceProvenanceHoldJournal;
    $baseline = $journal->snapshot();
    $context = ['database' => DB::selectOne('SELECT current_database() AS name')->name,
        'package_sha256' => SourceProvenanceHoldJournal::hash($baseline), ...$runtime];
    labCheck($context['database'] === 'expadu_staging', 'Wrong source-hold target');
    $eligible = DB::table('spots')->whereNull('source')->where('is_active', true)->where('is_recommendable', true)->whereNull('canonical_spot_id')->count();
    $aliases = DB::table('spots')->whereNull('source')->whereNotNull('canonical_spot_id')->count();
    $active = count(array_filter($baseline['spots'], static fn ($r): bool => $r['is_active']));
    $recommendable = count(array_filter($baseline['spots'], static fn ($r): bool => $r['is_recommendable']));
    $identityAfter = $target->query('SELECT current_user AS role,pg_backend_pid() AS pid,txid_current_snapshot()::text AS snapshot')->fetch(PDO::FETCH_ASSOC);
    labCheck($identityAfter === $identityBefore && $target->query('SHOW transaction_read_only')->fetchColumn() === 'on', 'Read-only snapshot identity changed');
    $package = ['operation_id' => (string) Str::uuid(), 'context' => $context, 'baseline' => $baseline,
        'actor' => 'reviewed-staging-source-provenance-hold',
        'reason' => 'Exclude records without source provenance while retaining every stored ID and saved reference.'];
    file_put_contents(__DIR__.'/staging-source-hold-package-private.json', json_encode($package, JSON_THROW_ON_ERROR));
    $summary = ['status' => 'read_only_native_baseline_prepared', 'database' => $context['database'],
        'source_null_records' => count($baseline['spots']), 'active_source_null_rows' => $active,
        'recommendable_source_null_rows' => $recommendable, 'active_recommendable_unlinked_source_null_rows' => $eligible,
        'existing_source_null_aliases' => $aliases, 'evidence_rows_by_table' => array_map('count', $baseline['evidence']),
        'package_sha256' => $context['package_sha256'], ...$runtime, 'catalogue_select_queries' => $queryCount,
        'safety_guard_refusals_verified' => $guardChecks, 'read_only_transaction' => true,
        'raw_rows_exported' => false, 'real_user_rows_returned' => false, 'live_hold_applied' => false,
        'staging_changed' => false, 'production_changed' => false, 'checked_at' => gmdate('c')];
    file_put_contents(__DIR__.'/staging-source-hold-package-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::setDefaultConnection($previous);
    $target->rollBack();
}
