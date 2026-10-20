<?php

declare(strict_types=1);

// Operational catalogue export. It never boots the deployed application.
require '/var/www/html/vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable('/var/www/html')->safeLoad();
if (getenv('APP_ENV') !== 'staging' || getenv('DB_DATABASE') !== 'expadu_staging' || getenv('DB_URL')
    || getenv('APP_COMMIT') !== '89289db9641bb75a563e74b44be9b4717bd61b22') {
    throw new RuntimeException('Expected staging image/database without connection URL.');
}
$pdo = new PDO('pgsql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: 5432).';dbname=expadu_staging',
    getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
$pdo->exec("SET LOCAL statement_timeout='45s'");
$pdo->exec("SET LOCAL TIME ZONE 'UTC'");
try {
    if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'expadu_staging'
        || $pdo->query('SHOW transaction_read_only')->fetchColumn() !== 'on') {
        throw new RuntimeException('Read-only staging snapshot required.');
    }
    $policy = SnapshotPolicy::schema();
    $result = ['schema_version' => 1, 'exported_at' => gmdate('c'), 'application_commit' => getenv('APP_COMMIT'),
        'row_format' => 'postgresql_column_text', 'environment' => 'staging',
        'scope' => 'Places catalogue only; no user data, private plans, sessions, feedback or operation receipts',
        'columns' => [], 'tables' => [], 'redactions' => [], 'table_hashes' => [],
        'staging_changed' => false, 'production_changed' => false, 'transaction_read_only' => true];
    foreach ($policy as $table => $expected) {
        $query = $pdo->prepare("SELECT column_name,udt_name,is_nullable FROM information_schema.columns WHERE table_schema='public' AND table_name=? ORDER BY ordinal_position");
        $query->execute([$table]);
        $actual = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($actual !== $expected) {
            throw new RuntimeException('Catalogue schema drift: '.$table);
        }
        $names = SnapshotPolicy::columns($table, array_column($actual, 'column_name'));
        $result['columns'][$table] = $actual;
        $expressions = implode(',', array_map(static fn (string $name): string => '"'.$name.'"::text AS "'.$name.'"', $names));
        $where = match ($table) {
            'media_attachments' => " WHERE mediable_type='App\\Models\\Spot' AND EXISTS (SELECT 1 FROM spots WHERE spots.id=media_attachments.mediable_id)",
            'media_assets' => " WHERE id IN (SELECT media_asset_id FROM media_attachments WHERE mediable_type='App\\Models\\Spot' AND EXISTS (SELECT 1 FROM spots WHERE spots.id=media_attachments.mediable_id))",
            default => '',
        };
        $rows = $pdo->query('SELECT '.$expressions.' FROM public."'.$table.'"'.$where.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $result['redactions'][$table] = 0;
        foreach ($rows as &$row) {
            $cleaned = SnapshotPolicy::sanitize($table, $row);
            if ($cleaned !== $row) {
                $result['redactions'][$table]++;
                foreach ($actual as $column) {
                    $key = $column['column_name'];
                    if ($column['udt_name'] === 'jsonb' && $cleaned[$key] !== $row[$key]) {
                        $canonical = $pdo->prepare('SELECT CAST(? AS jsonb)::text');
                        $canonical->execute([$cleaned[$key]]);
                        $cleaned[$key] = $canonical->fetchColumn();
                    }
                }
            }
            $row = $cleaned;
        }
        unset($row);
        $result['tables'][$table] = $rows;
        $result['table_hashes'][$table] = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    if ($pdo->query('SHOW transaction_read_only')->fetchColumn() !== 'on') {
        throw new RuntimeException('Snapshot left read-only mode.');
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} finally {
    $pdo->rollBack();
}
