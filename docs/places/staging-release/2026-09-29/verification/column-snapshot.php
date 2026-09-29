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

$tables = ['spots', 'veedels', 'place_fact_observations', 'place_fact_corrections', 'place_fact_revisions', 'place_destination_reviews', 'place_reconciliations', 'media_assets', 'media_attachments'];
$quote = static fn (string $column): string => '"'.str_replace('"', '""', $column).'"';
$path = $input.'/catalogue-column-text-before.json';
stagingPackCheck(! file_exists($path), 'Typed snapshot exists; refusing overwrite');
DB::beginTransaction();
DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
try {
    $snapshot = ['environment' => 'staging', 'captured_at' => gmdate('c'), 'row_format' => 'postgresql_column_text_array', 'tables' => []];
    $summaryTables = [];
    foreach ($tables as $table) {
        $columns = array_map(static fn ($r) => ['name' => $r->attname, 'generated' => $r->generated, 'type' => $r->data_type, 'not_null' => $r->attnotnull], DB::select("SELECT attname,attgenerated<>'' AS generated,format_type(atttypid,atttypmod) AS data_type,attnotnull FROM pg_attribute WHERE attrelid=to_regclass(?) AND attnum>0 AND NOT attisdropped ORDER BY attnum", ['public.'.$table]));
        $expressions = implode(',', array_map(static fn ($c) => $quote($c['name']).'::text', $columns));
        // Each source value remains PostgreSQL's native text, including JSON and EWKB.
        $rows = array_map(static fn ($row) => $row->raw, DB::select('SELECT to_json(ARRAY['.$expressions.'])::text AS raw FROM public."'.$table.'" ORDER BY id'));
        $snapshot['tables'][$table] = ['columns' => $columns, 'rows' => $rows];
        $summaryTables[$table] = ['rows' => count($rows), 'columns' => count($columns), 'row_text_sha256' => hash('sha256', implode("\n", $rows))];
    }
    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
    stagingPackCheck(file_put_contents($path, $json) === strlen($json), 'Incomplete column-text snapshot');
    stagingPackCheck(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) === $snapshot, 'Outer serialization changed source text');
    $summary = ['captured_at' => $snapshot['captured_at'], 'environment' => 'staging', 'read_only' => true, 'row_format' => $snapshot['row_format'], 'raw_data_retained_on_server' => true, 'existing_user_accounts_or_plans_loaded' => false, 'tables' => $summaryTables, 'snapshot_sha256' => hash('sha256', $json), 'native_column_text_preserved' => true, 'full_database_restore_tested' => false];
    file_put_contents($input.'/column-snapshot-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::rollBack();
}
