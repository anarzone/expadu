<?php

use App\Console\Commands\ImportOsmSpots;

require getcwd().'/vendor/autoload.php';

$method = new ReflectionMethod(ImportOsmSpots::class, 'httpUrlTag');
$command = new ImportOsmSpots;
$checked = 0;
$normalized = 0;
$mismatches = [];
foreach (file(__DIR__.'/2026-09-28/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    if ($record['source'] !== 'osm') {
        continue;
    }
    $source = $record['tags']['contact:website'] ?? $record['tags']['website'] ?? null;
    $actual = $method->invoke($command, $source);
    $checked++;
    if ($actual !== $record['website']) {
        $mismatches[] = ['key' => $record['key'], 'expected' => $record['website'], 'actual' => $actual];
    }
    if ($actual !== null && $actual !== $source) {
        $normalized++;
    }
}
$report = ['checked' => $checked, 'normalized_links' => $normalized, 'mismatches' => $mismatches, 'passed' => $mismatches === []];
file_put_contents(__DIR__.'/2026-09-28/website-verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit($mismatches === [] ? 0 : 1);
