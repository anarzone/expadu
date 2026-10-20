<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/../local-catalogue/bootstrap-local.php';
require __DIR__.'/../production-pack/apply-pack.php';
require __DIR__.'/native-checks.php';

$private = getenv('PLACES_LOCAL_PRIVATE').'/held-public-facilities';
$read = static fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$summary = $read(__DIR__.'/2026-10-01/rehearsal-summary.json');
localCheck(hash_file('sha256', $private.'/selection.json') === $summary['selection_sha256']
    && hash_file('sha256', $private.'/source-proof.json') === $summary['source_proof_sha256'], 'Frozen source inputs changed.');
$selection = $read($private.'/selection.json');
$proof = $read($private.'/source-proof.json');
$original = $read($private.'/native-preparation.json');
DB::select("SELECT pg_advisory_lock(hashtext('exp69_local_catalogue_rehearsal'))");
DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    localVerify(localPdo(), localSnapshot());
    [$records, $held] = heldPreparedRecords($selection, $proof, array_column(localTypedRows('spots'), null, 'id'));
    localCheck($held === [] && count($records) === $summary['qualified_facilities']
        && preparedPlaceRowFingerprint(['records' => $records, 'held' => $held]) === preparedPlaceRowFingerprint($original),
        'The tightened source guard changed the rehearsed proposal.');
    $result = ['status' => 'passed', 'checked_at' => gmdate('c'), 'native_source_records_rechecked' => count($records),
        'frozen_way_topologies_rechecked' => count(array_filter($records, static fn (array $record): bool => str_starts_with($record['source_id'], 'way/'))),
        'prepared_records_unchanged' => true, 'transaction_read_only' => true, 'remote_changed' => false,
        'selection_sha256' => $summary['selection_sha256'], 'source_proof_sha256' => $summary['source_proof_sha256'],
        'native_guard_sha256' => hash_file('sha256', __DIR__.'/native-checks.php')];
} finally {
    DB::rollBack();
    DB::select("SELECT pg_advisory_unlock(hashtext('exp69_local_catalogue_rehearsal'))");
}
file_put_contents(__DIR__.'/2026-10-01/source-guard-verification.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
