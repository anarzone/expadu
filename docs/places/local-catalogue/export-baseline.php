<?php

require __DIR__.'/bootstrap-local.php';
use Illuminate\Support\Facades\DB;

$path = getenv('PLACES_LOCAL_PRIVATE').'/places-only.json';

DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $snapshot = localSnapshot();
    $tables = [];
    foreach (array_keys($snapshot['tables']) as $table) {
        $tables[$table] = localTypedRows($table);
    }
    $encoded = json_encode(['exported_at' => $snapshot['exported_at'], 'tables' => $tables], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (file_exists($path)) {
        localCheck(file_get_contents($path) === $encoded, 'Existing typed baseline differs.');
    } else {
        file_put_contents($path, $encoded);
    }
} finally {
    DB::rollBack();
}
echo "Private typed baseline written from the verified local catalogue.\n";
