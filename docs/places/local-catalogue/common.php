<?php

declare(strict_types=1);

require_once __DIR__.'/SnapshotPolicy.php';
umask(0077);

function localCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function localPdo(): PDO
{
    localCheck(getenv('APP_ENV') === 'testing'
        && getenv('DB_DATABASE') === 'exp69_local_catalogue_20261001'
        && in_array(getenv('DB_HOST'), ['localhost', '127.0.0.1', '::1'], true)
        && ! getenv('DB_URL'), 'Only the dedicated loopback catalogue database is allowed.');
    $pdo = new PDO('pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname=exp69_local_catalogue_20261001',
        getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    localCheck($pdo->query('SELECT current_database()')->fetchColumn() === 'exp69_local_catalogue_20261001', 'Wrong local database.');
    $pdo->exec("SET TIME ZONE 'UTC'");
    $pdo->exec("SET statement_timeout='120s'");

    return $pdo;
}
function localSnapshot(): array
{
    $path = getenv('PLACES_LOCAL_PRIVATE').'/catalogue.json';
    $receipt = json_decode(file_get_contents(__DIR__.'/2026-10-01/snapshot-summary.json'), true, flags: JSON_THROW_ON_ERROR);
    localCheck(hash_file('sha256', $path) === $receipt['snapshot_sha256'], 'Private snapshot checksum mismatch.');
    $doc = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    localCheck($doc['columns'] === SnapshotPolicy::schema() && $doc['transaction_read_only']
        && ! $doc['staging_changed'] && ! $doc['production_changed'], 'Snapshot scope mismatch.');

    return $doc;
}
function localRows(PDO $pdo, string $table, array $columns): array
{
    $names = SnapshotPolicy::columns($table, array_column($columns, 'column_name'));
    $select = implode(',', array_map(static fn (string $n): string => '"'.$n.'"::text AS "'.$n.'"', $names));

    return $pdo->query('SELECT '.$select.' FROM public."'.$table.'" ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}
function localHash(array $rows): string
{
    return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
function localVerify(PDO $pdo, array $snapshot): array
{
    $counts = [];
    foreach ($snapshot['tables'] as $table => $expected) {
        $rows = localRows($pdo, $table, $snapshot['columns'][$table]);
        localCheck(localHash($rows) === $snapshot['table_hashes'][$table], 'Restored native values differ: '.$table);
        $counts[$table] = count($rows);
    }
    foreach (['users', 'user_places', 'spot_feedback', 'composer_workspaces', 'reviews', 'place_catalogue_operations'] as $table) {
        $exists = $pdo->prepare('SELECT to_regclass(?) IS NOT NULL');
        $exists->execute(['public.'.$table]);
        if ($exists->fetchColumn()) {
            localCheck((int) $pdo->query('SELECT count(*) FROM public."'.$table.'"')->fetchColumn() === 0, 'Private/operation rows entered local catalogue.');
        }
    }

    return $counts;
}
function localSummary(string $name, array $summary): void
{
    file_put_contents(getenv('PLACES_LOCAL_REPORTS').'/'.$name, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
}
