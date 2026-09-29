<?php

use App\Models\Spot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/apply-pack.php';
if (! app()->environment('testing') || DB::selectOne('SELECT current_database() AS name')->name !== 'exp72_ready_20260928') {
    throw new RuntimeException('Only the dedicated local rehearsal database is allowed.');
}
function replayRows(string $table): array
{
    return array_column(array_map(static fn (object $row): array => json_decode($row->raw, true, flags: JSON_THROW_ON_ERROR), DB::select('SELECT row_to_json(t)::text AS raw FROM '.$table.' t ORDER BY id')), null, 'id');
}
$all = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file(__DIR__.'/2026-09-28/records.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$groups = [];
$records = [];
foreach ($all as $record) {
    $group = $record['source'].':'.$record['name_kind'].':'.($record['existing_id'] === null ? 'new' : 'existing');
    if (in_array('--all', $argv, true) || ($groups[$group] ?? 0) < 10) {
        $records[] = $record;
        $groups[$group] = ($groups[$group] ?? 0) + 1;
    }
}
$baseline = replayRows('spots');
$manifest = json_decode(file_get_contents(__DIR__.'/2026-09-28/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
DB::beginTransaction();
try {
    $mapping = applyPreparedPlaces($records, $baseline, $manifest['records_sha256']);
    $first = [];
    foreach (['spots', 'place_fact_observations', 'place_fact_revisions'] as $table) {
        $first[$table] = replayRows($table);
    }
    foreach ($records as &$record) {
        $record['existing_id'] = $mapping[$record['key']]['id'];
    }
    unset($record);
    Spot::saving(static function (Spot $spot): void {
        echo json_encode(['replay_dirty' => $spot->id, 'values' => $spot->getDirty(), 'original' => array_intersect_key($spot->getOriginal(), $spot->getDirty())], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    });
    usleep(1100000);
    applyPreparedPlaces($records, $first['spots'], $manifest['records_sha256']);
    $changes = [];
    foreach ($first as $table => $rows) {
        foreach (replayRows($table) as $id => $row) {
            if ($row !== ($rows[$id] ?? null)) {
                $diff = [];
                foreach ($row as $key => $value) {
                    if ($value !== ($rows[$id][$key] ?? null)) {
                        $diff[$key] = ['before' => $rows[$id][$key] ?? null, 'after' => $value];
                    }
                }
                $changes[$table][$id] = $diff;
            }
        }
    }
    echo json_encode(['records' => count($records), 'changes' => $changes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::rollBack();
}
