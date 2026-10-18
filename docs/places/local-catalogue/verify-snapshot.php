<?php

declare(strict_types=1);
require __DIR__.'/common.php';
$pdo = localPdo();
$pdo->beginTransaction();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
try {
    $snapshot = localSnapshot();
    $counts = localVerify($pdo, $snapshot);
    localSummary('snapshot-verification.json', ['status' => 'passed', 'checked_at' => gmdate('c'),
        'table_counts' => $counts, 'native_table_hashes_match' => true, 'user_rows' => 0,
        'remote_changed' => false, 'row_format' => $snapshot['row_format']]);
} finally {
    $pdo->rollBack();
}
