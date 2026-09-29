<?php

// Prepare only public package data and expected fingerprints; never contact a server.
if ($argc !== 3 || ! is_dir($argv[1]) || ! is_dir($argv[2])) {
    fwrite(STDERR, "Usage: php prepare-rehearsal-input.php <repository-root> <existing-output-directory>\n");
    exit(1);
}
$root = realpath($argv[1]);
$output = realpath($argv[2]);
$pack = $root.'/docs/places/production-pack/2026-09-28';
require $root.'/docs/places/production-pack/apply-pack.php';
$manifest = json_decode(file_get_contents($pack.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$baselinePath = $root.'/storage/app/private/places-research/production-pack-2026-09-28/places-only.json';
if (hash_file('sha256', $baselinePath) !== $manifest['baseline_sha256'] || hash_file('sha256', $pack.'/records.jsonl') !== $manifest['records_sha256']) {
    throw new RuntimeException('Frozen package or baseline checksum changed.');
}
$baseline = array_column(json_decode(file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR)['tables']['spots'], null, 'id');
$entries = [];
foreach (file($pack.'/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    $id = $record['existing_id'];
    $entries[$record['key']] = ['id' => $id, 'sha256' => $id === null ? null : preparedPlaceRowFingerprint($baseline[$id])];
}
if (count($entries) !== 4643 || count($entries) !== $manifest['records']) {
    throw new RuntimeException('Unexpected package record count.');
}
umask(0077);
foreach (['records.jsonl', 'manifest.json'] as $file) {
    if (! copy($pack.'/'.$file, $output.'/'.$file)) {
        throw new RuntimeException('Could not copy package input.');
    }
}
copy($root.'/docs/places/production-pack/apply-pack.php', $output.'/apply-pack.php');
file_put_contents($output.'/fingerprints.json', json_encode($entries, JSON_THROW_ON_ERROR));
$checksums = [];
foreach (['records.jsonl', 'manifest.json', 'apply-pack.php', 'fingerprints.json'] as $file) {
    $checksums[$file] = hash_file('sha256', $output.'/'.$file);
}
file_put_contents($output.'/checksums.json', json_encode($checksums, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode(['prepared_records' => count($entries), 'raw_baseline_copied' => false, 'checksums' => $checksums], JSON_THROW_ON_ERROR).PHP_EOL;
