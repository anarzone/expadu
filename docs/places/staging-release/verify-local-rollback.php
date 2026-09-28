<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::selectOne('SELECT current_database() AS name')->name !== 'exp72_ready_20260928') {
    throw new RuntimeException('Only the dedicated isolated local database is allowed.');
}
$root = getenv('PLACES_PACK_ROOT');
require $root.'/docs/places/production-pack/apply-pack.php';
$path = $root.'/storage/app/private/places-research/production-pack-2026-09-28/places-only.json';
$manifest = json_decode(file_get_contents($root.'/docs/places/production-pack/2026-09-28/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if (hash_file('sha256', $path) !== $manifest['baseline_sha256']) {
    throw new RuntimeException('Frozen baseline changed.');
}
$baseline = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$report = ['verified_at' => gmdate('c'), 'scope' => 'local public-data database after cancelled football experiment', 'tables' => [], 'database_writes' => false, 'staging_queried' => false];
DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    foreach (['spots', 'veedels', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions'] as $table) {
        $expected = [];
        foreach ($baseline['tables'][$table] as $row) {
            $expected[$row['id']] = preparedPlaceRowFingerprint($row);
        }
        $actual = [];
        foreach (DB::select('SELECT row_to_json(t)::text AS raw FROM '.$table.' t ORDER BY id') as $row) {
            $value = json_decode($row->raw, true, flags: JSON_THROW_ON_ERROR);
            $actual[$value['id']] = preparedPlaceRowFingerprint($value);
        }
        ksort($expected);
        ksort($actual);
        if ($actual !== $expected) {
            throw new RuntimeException('Local table differs from the frozen baseline: '.$table);
        }
        $report['tables'][$table] = ['rows' => count($actual), 'matches_frozen_baseline' => true];
    }
    if (DB::table('users')->count() !== 0) {
        throw new RuntimeException('Unexpected local user remains.');
    }
    $report['users_remaining'] = 0;
} finally {
    DB::rollBack();
}
file_put_contents(__DIR__.'/2026-09-28/interrupted-experiment-rollback.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
