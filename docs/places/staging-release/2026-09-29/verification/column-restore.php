<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

// There is deliberately no commit mode. Staging execution requires prior approval.
if ($argc !== 2 || $argv[1] !== '--staging') {
    fwrite(STDERR, "Choose exactly --staging. This helper checks a private snapshot using temporary tables.\n");
    exit(1);
}
$isStaging = $argv[1] === '--staging';
$input = getenv('PLACES_REHEARSAL_INPUT');
if (! is_string($input) || ! is_dir($input)) {
    throw new RuntimeException('An explicit prepared input directory is required.');
}
umask(0077);
$privateExceptionHandler = static function (Throwable $error) use ($input): never {
    file_put_contents($input.'/column-snapshot-failure-private.txt', $error::class.': '.$error->getMessage().PHP_EOL);
    fwrite(STDERR, "Rehearsal failed during preflight; details stay in the evidence directory.\n");
    exit(1);
};
set_exception_handler($privateExceptionHandler);
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$privateConfiguration = [
    // Providers construct the rate limiter during boot; isolate its store first.
    'cache.default' => 'array',
    'cache.limiter' => 'array',
    'queue.default' => 'sync',
    'session.driver' => 'array',
    'services.composer_llm.enabled' => false,
    'sentry.dsn' => null,
    'sentry.traces_sample_rate' => 0,
    'logging.default' => 'places_rehearsal_private',
    'logging.channels.places_rehearsal_private' => ['driver' => 'single', 'path' => $input.'/laravel-private.log', 'permission' => 0600],
];
$app->afterBootstrapping(LoadConfiguration::class, static function () use ($privateConfiguration): void {
    config($privateConfiguration);
});
try {
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $error) {
    $privateExceptionHandler($error);
}
set_exception_handler($privateExceptionHandler);
// Kernel-reported API exceptions must not use the deployed Sentry transport.
$privateHub = new Hub;
$app->instance(HubInterface::class, $privateHub);
SentrySdk::setCurrentHub($privateHub);

function stagingPackCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

stagingPackCheck(app()->environment($isStaging ? 'staging' : 'testing'), 'Unexpected application environment.');
stagingPackCheck(SentrySdk::getCurrentHub()->getClient() === null && $app->make(HubInterface::class)->getClient() === null, 'Error reporting transport is not isolated.');
stagingPackCheck(DB::selectOne('SELECT current_database() AS name')->name === ($isStaging ? 'expadu_staging' : 'exp72_ready_20260928'), 'Unexpected database.');
if ($isStaging) {
    stagingPackCheck(rtrim((string) config('app.url'), '/') === 'https://app.staging.expadu.com', 'Unexpected staging URL.');
}

$path = $input.'/catalogue-column-text-before.json';
stagingPackCheck(hash_file('sha256', $path) === '1d76a549dc6064ac3d6c78c92a0363eb9fa11a722172a29ae556af11742803f9', 'Unreviewed snapshot');
$snapshot = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$tables = ['spots', 'veedels', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_destination_reviews', 'place_reconciliations', 'media_assets', 'media_attachments'];
stagingPackCheck($snapshot['row_format'] === 'postgresql_column_text_array' && array_keys($snapshot['tables']) === $tables, 'Unexpected snapshot scope');
$quote = static fn (string $column): string => '"'.str_replace('"', '""', $column).'"';
$report = ['checked_at' => gmdate('c'), 'environment' => 'staging', 'mode' => 'temporary_table_column_text_restore', 'snapshot_sha256' => hash_file('sha256', $path), 'verification_code_sha256' => hash_file('sha256', __FILE__), 'raw_records_exported' => false, 'private_users_or_plans_loaded' => false, 'persistent_catalogue_writes' => false, 'tables' => [], 'limitations' => ['Validates typed column values, generated columns, check/not-null/unique constraints and indexes against current schema', 'LIKE does not copy foreign keys or application triggers; external relationships and full-database disaster recovery are not exercised', 'Shared media may change independently; this test never overwrites live tables']];
try {
    DB::beginTransaction();
    DB::statement('SET LOCAL statement_timeout = 120000');
    DB::statement('SET LOCAL lock_timeout = 5000');
    foreach ($tables as $table) {
        $temp = 'places_recovery_'.$table;
        $columns = array_map(static fn ($r) => ['name' => $r->attname, 'generated' => $r->generated, 'type' => $r->data_type, 'not_null' => $r->attnotnull], DB::select("SELECT attname,attgenerated<>'' AS generated,format_type(atttypid,atttypmod) AS data_type,attnotnull FROM pg_attribute WHERE attrelid=to_regclass(?) AND attnum>0 AND NOT attisdropped ORDER BY attnum", ['public.'.$table]));
        stagingPackCheck($columns === $snapshot['tables'][$table]['columns'], 'Table schema changed: '.$table);
        DB::statement('CREATE TEMP TABLE "'.$temp.'" (LIKE public."'.$table.'" INCLUDING ALL) ON COMMIT DROP');
        $insertIndexes = array_keys(array_filter($columns, static fn ($c) => ! $c['generated']));
        $insertColumns = implode(',', array_map(static fn ($i) => $quote($columns[$i]['name']), $insertIndexes));
        $rowPlaceholder = '('.implode(',', array_fill(0, count($insertIndexes), '?')).')';
        foreach (array_chunk($snapshot['tables'][$table]['rows'], 100) as $chunk) {
            $bindings = [];
            foreach ($chunk as $json) {
                $row = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                foreach ($insertIndexes as $i) {
                    stagingPackCheck($row[$i] === null || is_string($row[$i]), 'Snapshot field was normalized');
                    $bindings[] = $row[$i];
                }
            }
            DB::insert('INSERT INTO pg_temp."'.$temp.'" ('.$insertColumns.') OVERRIDING SYSTEM VALUE VALUES '.implode(',', array_fill(0, count($chunk), $rowPlaceholder)), $bindings);
        }
        $expressions = implode(',', array_map(static fn ($c) => $quote($c['name']).'::text', $columns));
        $restored = array_map(static fn ($r) => $r->raw, DB::select('SELECT to_json(ARRAY['.$expressions.'])::text AS raw FROM pg_temp."'.$temp.'" ORDER BY id'));
        stagingPackCheck($restored === $snapshot['tables'][$table]['rows'], 'Restored native column text differs: '.$table);
        $report['tables'][$table] = ['restored_rows' => count($restored), 'exact_postgresql_column_text_match' => true];
    }
    $report['status'] = 'passed';
} catch (Throwable $error) {
    file_put_contents($input.'/restore-failure-private.txt', $error::class.': '.$error->getMessage());
    $report['status'] = 'failed';
    $report['error_details_exported'] = false;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}
$report['temporary_tables_removed'] = true;
foreach ($tables as $table) {
    if (DB::selectOne('SELECT to_regclass(?) IS NULL AS absent', ['pg_temp.places_recovery_'.$table])->absent !== true) {
        $report['temporary_tables_removed'] = false;
        $report['status'] = 'failed';
    }
}
$report['full_database_restore_tested'] = false;
file_put_contents($input.'/snapshot-restore-summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
exit($report['status'] === 'passed' ? 0 : 1);
