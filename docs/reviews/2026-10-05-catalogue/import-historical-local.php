<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// One-time, local-only historical seed. Never a staging promotion command.
require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/HistoricalCatalogueSnapshot.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
umask(0077);
$mode = $argv[1] ?? 'preview';
if (! in_array($mode, ['preview', 'apply', 'verify'], true)) {
    throw new DomainException('Use preview, apply or verify.');
}
$c = DB::connection()->getConfig();
if (! $app->environment('local') || $c['host'] !== '127.0.0.1' || $c['database'] !== 'expadu') {
    throw new DomainException('Only the owned local expadu database is allowed.');
}
$dir = storage_path('app/private/catalogue-review/2026-10-05');
if (! is_file($dir.'/before-compatibility.pgdump')) {
    throw new DomainException('The private recovery backup must exist first.');
}
$doc = HistoricalCatalogueSnapshot::read(storage_path('app/private/places-local/2026-10-01/catalogue.json'));
$offset = 1000000;
$tables = HistoricalCatalogueSnapshot::remap($doc['tables'], $offset);
foreach ($tables as &$rows) {
    // PostgreSQL ORDER BY the selected id::text alias is lexical, not numeric.
    usort($rows, static fn ($a, $b) => strcmp($a['id'], $b['id']));
}
unset($rows);
$pdo = DB::connection()->getPdo();
$pdo->exec("SET TIME ZONE 'UTC'");
$pdo->exec("SET statement_timeout='120s'");
$pdo->exec("SET lock_timeout='10s'");
$protected = ['users', 'composer_workspaces', 'saved_journeys', 'event_bookmarks', 'user_places', 'reviews', 'spot_feedback', 'spot_enrichments', 'venues', 'events'];
$hash = static fn (array $rows): string => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$writeReceipt = static function (string $path, array $receipt): void {
    $encoded = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $temporary = $path.'.tmp';
    if (file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded) || ! rename($temporary, $path)) {
        throw new RuntimeException('Could not preserve the local import receipt: '.$path);
    }
};
$protectedHash = static function () use ($pdo, $protected, $hash): array {
    $out = [];
    foreach ($protected as $table) {
        $out[$table] = $hash($pdo->query('SELECT row_to_json(t)::text AS payload FROM "'.$table.'" t ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    return $out;
};
$readRows = static function (string $table) use ($pdo, $doc): array {
    $columns = array_column($doc['columns'][$table], 'column_name');
    $select = implode(',', array_map(static fn ($name) => '"'.$name.'"::text AS "'.$name.'"', $columns));

    return $pdo->query('SELECT '.$select.' FROM "'.$table.'" ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
};
$verify = static function () use ($tables, $readRows, $hash): array {
    $result = [];
    foreach ($tables as $table => $expected) {
        $actual = $readRows($table);
        if ($hash($actual) !== $hash($expected)) {
            $differences = [];
            foreach ($actual as $i => $row) {
                foreach ($row as $key => $value) {
                    if ($value !== ($expected[$i][$key] ?? null)) {
                        $differences[] = ['row' => $i, 'field' => $key, 'actual' => mb_substr((string) $value, 0, 160), 'expected' => mb_substr((string) ($expected[$i][$key] ?? ''), 0, 160)];
                    }
                    if (count($differences) >= 3) {
                        break 2;
                    }
                }
            }
            throw new DomainException('Imported values differ: '.$table.' '.json_encode($differences));
        }
        $result[$table] = ['count' => count($actual), 'sha256' => $hash($actual)];
    }

    return $result;
};
if ($mode === 'verify') {
    echo json_encode(['mode' => $mode, 'tables' => $verify(), 'historical_as_of' => $doc['exported_at']], JSON_PRETTY_PRINT).PHP_EOL;
    exit;
}
$pdo->beginTransaction();
$sequences = [];
try {
    foreach ([...array_keys($tables), ...$protected] as $table) {
        $pdo->exec('LOCK TABLE "'.$table.'" IN SHARE ROW EXCLUSIVE MODE');
    }
    $beforeProtected = $protectedHash();
    $before = [];
    foreach ($tables as $table => $rows) {
        $before[$table] = $hash($readRows($table));
        $count = (int) $pdo->query('SELECT count(*) FROM "'.$table.'"')->fetchColumn();
        if ($table === 'place_fact_revisions') {
            $empty = $count <= 1 && (int) $pdo->query('SELECT coalesce(max(revision),0) FROM place_fact_revisions')->fetchColumn() === 0;
        } else {
            $empty = $count === 0;
        }
        if (! $empty) {
            throw new DomainException('Import refuses existing catalogue data: '.$table);
        }
    }
    foreach (['venues', 'reviews', 'spot_feedback', 'spot_enrichments'] as $table) {
        if ((int) $pdo->query('SELECT count(*) FROM "'.$table.'"')->fetchColumn() !== 0) {
            throw new DomainException('Target already has place references: '.$table);
        }
    }
    foreach ($pdo->query('SELECT row_to_json(composer_workspaces)::text FROM composer_workspaces')->fetchAll(PDO::FETCH_COLUMN) as $payload) {
        preg_match_all('/spot:(\d+)/', $payload, $matches);
        foreach ($matches[1] as $id) {
            if ((int) $id >= $offset) {
                throw new DomainException('Reserved user place ID conflicts with the allocated import range.');
            }
        }
    }
    foreach ($tables as $table => $rows) {
        if ($rows === []) {
            continue;
        }
        $names = array_column($doc['columns'][$table], 'column_name');
        $columns = implode(',', array_map(static fn ($name) => '"'.$name.'"', $names));
        $sql = 'INSERT INTO "'.$table.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($names), '?')).')';
        if ($table === 'place_fact_revisions') {
            $sql .= ' ON CONFLICT(id) DO UPDATE SET revision=EXCLUDED.revision,updated_at=EXCLUDED.updated_at';
        }
        $statement = $pdo->prepare($sql);
        $links = match ($table) {
            'spots' => ['parent_spot_id', 'canonical_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id'],
            'place_fact_observations' => ['restores_observation_id'],
            'place_fact_corrections' => ['supersedes_id'],
            default => [],
        };
        $deferred = [];
        foreach ($rows as $row) {
            foreach ($links as $field) {
                if ($row[$field] !== null) {
                    $deferred[$row['id']][$field] = $row[$field];
                    $row[$field] = null;
                }
            }
            $statement->execute(array_map(static fn ($name) => $row[$name], $names));
        }
        foreach ($deferred as $id => $values) {
            $set = implode(',', array_map(static fn ($key) => '"'.$key.'"=?', array_keys($values)));
            $pdo->prepare('UPDATE "'.$table.'" SET '.$set.' WHERE id=?')->execute([...array_values($values), $id]);
        }
        $sequence = $pdo->prepare("SELECT pg_get_serial_sequence(?,'id')");
        $sequence->execute([$table]);
        if ($name = $sequence->fetchColumn()) {
            $sequences[$name] = max(array_map(static fn ($row) => (int) $row['id'], $rows));
        }
    }
    $verified = $verify();
    if ($beforeProtected !== $protectedHash()) {
        throw new DomainException('Protected records changed; rolling back.');
    }
    $receipt = ['mode' => $mode, 'checked_at' => gmdate('c'), 'historical_as_of' => $doc['exported_at'], 'snapshot_sha256' => HistoricalCatalogueSnapshot::SHA256, 'local_spot_id_offset' => $offset, 'tables' => $verified, 'protected_hashes' => $beforeProtected, 'new_source_qualification' => false, 'remote_changed' => false, 'history_note' => 'Raw review snapshots and source evidence retain original source-database IDs; live relationship columns are mapped by the recorded offset. No historical review operation was replayed.'];
    if ($mode === 'apply') {
        if (! is_file($dir.'/preview-receipt.json')) {
            throw new DomainException('A successful rollback preview is required.');
        }
        $preview = json_decode(file_get_contents($dir.'/preview-receipt.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($preview['tables'] !== $verified || $preview['protected_hashes'] !== $beforeProtected || $preview['snapshot_sha256'] !== HistoricalCatalogueSnapshot::SHA256 || ! $preview['rollback_verified']) {
            throw new DomainException('Target or source changed since preview.');
        }
        foreach ($sequences as $sequence => $maximum) {
            // ALTER SEQUENCE restarts transactionally; unlike setval it rolls back on failure.
            if (! preg_match('/^[a-z0-9_.]+$/D', $sequence)) {
                throw new DomainException('Unexpected sequence name.');
            }
            $pdo->exec('ALTER SEQUENCE '.$sequence.' RESTART WITH '.($maximum + 1));
        }
        $writeReceipt($dir.'/apply-intent.json', $receipt);
        $pdo->commit();
    } else {
        $pdo->rollBack();
        foreach ($before as $table => $digest) {
            if ($hash($readRows($table)) !== $digest) {
                throw new DomainException('Rollback verification failed: '.$table);
            }
        }
        if ($protectedHash() !== $beforeProtected) {
            throw new DomainException('Protected records changed during preview.');
        }
        $receipt['rollback_verified'] = true;
    }
    $writeReceipt($dir.'/'.$mode.'-receipt.json', $receipt);
    echo json_encode(['status' => $mode === 'apply' ? 'imported_historical_snapshot' : 'preview_rolled_back', 'counts' => array_map('count', $tables), 'protected_records_unchanged' => true, 'local_spot_id_offset' => $offset, 'receipt' => $dir.'/'.$mode.'-receipt.json'], JSON_PRETTY_PRINT).PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
