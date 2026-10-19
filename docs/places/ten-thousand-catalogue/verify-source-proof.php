<?php

require __DIR__.'/../local-catalogue/bootstrap-local.php';
require __DIR__.'/../production-pack/apply-pack.php';
require __DIR__.'/native-checks.php';

$private = getenv('PLACES_LOCAL_PRIVATE').'/ten-thousand-catalogue';
$read = static fn (string $path): array => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$selectionSummary = $read(__DIR__.'/2026-10-01/selection-summary.json');
$sourceSummary = $read(__DIR__.'/2026-10-01/source-summary.json');
localCheck(hash_file('sha256', $private.'/selection.json') === $selectionSummary['selection_sha256']
    && hash_file('sha256', $private.'/source-proof.json') === $sourceSummary['source_proof_sha256']
    && $sourceSummary['selection_sha256'] === $selectionSummary['selection_sha256'], 'Frozen source evidence changed.');
[$prepared, $held] = namedPreparedRecords($read($private.'/selection.json'), $read($private.'/source-proof.json'), array_column(localTypedRows('spots'), null, 'id'), $read($private.'/type-map.json'));
$path = $private.'/native-preparation.json';
localCheck(! file_exists($path), 'Preserve native preparation.');
file_put_contents($path, json_encode(['records' => $prepared, 'held' => $held], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$summary = ['status' => 'source_and_native_geometry_checked', 'prepared' => count($prepared), 'held' => count($held), 'hold_reasons' => array_count_values(array_merge([], ...array_column($held, 'reasons'))), 'type_map_sha256' => hash_file('sha256', $private.'/type-map.json'), 'native_preparation_sha256' => hash_file('sha256', $path), 'selection_sha256' => $selectionSummary['selection_sha256'], 'source_proof_sha256' => $sourceSummary['source_proof_sha256'], 'database_changed' => false, 'remote_changed' => false];
file_put_contents(__DIR__.'/2026-10-01/native-source-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
localVerify(localPdo(), localSnapshot());
echo json_encode($summary).PHP_EOL;
