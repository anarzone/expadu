<?php

declare(strict_types=1);
require __DIR__.'/common.php';
$pdo = localPdo();
$snapshot = localSnapshot();
$pdo->beginTransaction();
try {
    localCheck((int) $pdo->query('SELECT count(*) FROM users')->fetchColumn() === 0, 'Local users must be empty.');
    foreach ($snapshot['tables'] as $table => $rows) {
        $count = (int) $pdo->query('SELECT count(*) FROM public."'.$table.'"')->fetchColumn();
        if ($table === 'place_fact_revisions') {
            localCheck($count <= 1 && (int) $pdo->query('SELECT coalesce(max(revision),0) FROM place_fact_revisions')->fetchColumn() === 0,
                'Fact revisions already contain local catalogue work.');
        } else {
            localCheck($count === 0, 'Restore refuses a populated table: '.$table);
        }
    }
    foreach ($snapshot['tables'] as $table => $rows) {
        if ($rows === []) {
            continue;
        }
        $names = SnapshotPolicy::columns($table, array_column($snapshot['columns'][$table], 'column_name'));
        $quoted = implode(',', array_map(static fn (string $n): string => '"'.$n.'"', $names));
        $sql = 'INSERT INTO public."'.$table.'" ('.$quoted.') VALUES ('.implode(',', array_fill(0, count($names), '?')).')';
        if ($table === 'place_fact_revisions') {
            $sql .= ' ON CONFLICT(id) DO UPDATE SET revision=EXCLUDED.revision,updated_at=EXCLUDED.updated_at';
        }
        $insert = $pdo->prepare($sql);
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
            $insert->execute(array_map(static fn (string $name): mixed => $row[$name], $names));
        }
        foreach ($deferred as $id => $values) {
            $set = implode(',', array_map(static fn (string $key): string => '"'.$key.'"=?', array_keys($values)));
            $update = $pdo->prepare('UPDATE public."'.$table.'" SET '.$set.' WHERE id=?');
            $update->execute([...array_values($values), $id]);
        }
        $sequence = $pdo->prepare("SELECT pg_get_serial_sequence(?,'id')");
        $sequence->execute(['public.'.$table]);
        if ($name = $sequence->fetchColumn()) {
            $set = $pdo->prepare('SELECT setval(CAST(? AS regclass),CAST(? AS bigint),true)');
            $set->execute([$name, max(array_map(static fn (array $row): int => (int) $row['id'], $rows))]);
        }
        echo json_encode(['phase' => 'local_restore', 'table' => $table, 'rows' => count($rows)]).PHP_EOL;
    }
    $counts = localVerify($pdo, $snapshot);
    $pdo->commit();
    localSummary('restore-summary.json', ['status' => 'restored_and_verified', 'database' => 'exp69_local_catalogue_20261001',
        'checked_at' => gmdate('c'), 'snapshot_exported_at' => $snapshot['exported_at'], 'table_counts' => $counts,
        'native_table_hashes_match' => true, 'private_user_rows' => 0, 'remote_changed' => false,
        'scope' => 'Places and Composer place candidates; no event/user catalogue imported']);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
