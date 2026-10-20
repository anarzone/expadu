<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::selectOne('SELECT current_database() AS name')->name !== 'exp72_ready_20260928') {
    throw new RuntimeException('Only the dedicated local rehearsal database is allowed.');
}
if (DB::table('spots')->exists() || DB::table('users')->exists()) {
    throw new RuntimeException('Places and users tables must be empty before baseline import.');
}
$root = getenv('PLACES_PACK_ROOT');
$path = $root.'/storage/app/private/places-research/production-pack-2026-09-28/places-only.json';
$document = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$counts = [];
$encode = static function (array $row): array {
    return array_map(static fn (mixed $v): mixed => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $v, $row);
};
DB::transaction(function () use ($document, $encode, &$counts): void {
    foreach (['veedels', 'spots', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions'] as $table) {
        $rows = $document['tables'][$table];
        $relationships = [];
        foreach ($rows as $row) {
            if ($table === 'spots') {
                foreach (['parent_spot_id', 'canonical_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id'] as $key) {
                    $relationships[$row['id']][$key] = $row[$key];
                    $row[$key] = null;
                }
            }
            if ($table === 'place_fact_revisions') {
                DB::table($table)->updateOrInsert(['id' => $row['id']], $encode($row));
            } else {
                DB::table($table)->insert($encode($row));
            }
        }
        foreach ($relationships as $id => $fields) {
            DB::table('spots')->where('id', $id)->update($fields);
        }
        if ($table !== 'place_fact_revisions' && $rows !== []) {
            DB::select("SELECT setval(pg_get_serial_sequence(?, 'id'), ?, true)", [$table, max(array_column($rows, 'id'))]);
        }
        $counts[$table] = count($rows);
    }
});
echo json_encode(['database' => 'exp72_ready_20260928', 'counts' => $counts, 'users_imported' => DB::table('users')->count(), 'remote_changed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
