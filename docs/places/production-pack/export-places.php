<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('staging')) {
    throw new RuntimeException('Only the staging place catalogue may be exported.');
}

DB::beginTransaction();
DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
try {
    $result = [
        'exported_at' => gmdate('c'),
        'environment' => app()->environment(),
        'scope' => 'place catalogue only; no users, personal plans, reviews, checkins, saved places, sessions or credentials',
        'tables' => [],
    ];
    foreach (['spots', 'veedels', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions'] as $table) {
        $result['tables'][$table] = array_map(
            static fn (object $row): array => json_decode($row->raw, true, flags: JSON_THROW_ON_ERROR),
            DB::select('SELECT row_to_json(t)::text AS raw FROM '.$table.' t ORDER BY id'),
        );
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::rollBack();
}
