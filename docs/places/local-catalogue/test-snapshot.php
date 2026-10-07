<?php

declare(strict_types=1);

$path = __DIR__.'/SnapshotPolicy.php';
if (! is_file($path)) {
    fwrite(STDERR, "FAIL: catalogue snapshot policy is missing.\n");
    exit(1);
}
require $path;
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$check(SnapshotPolicy::allowsTable('spots'), 'Place identities must be retained');
foreach (['users', 'sessions', 'spot_feedback', 'user_places', 'composer_workspaces', 'place_catalogue_operations'] as $table) {
    $check(! SnapshotPolicy::allowsTable($table), 'Private or operational table accepted: '.$table);
}
$row = SnapshotPolicy::sanitize('place_fact_corrections', [
    'id' => 7, 'actor' => 'private-reviewer', 'evidence' => 'Source says public',
    'reviewed_at' => '2026-09-30T12:00:00Z', 'revoked_at' => null,
]);
$check($row['actor'] === 'local-catalogue-review', 'Administrative actor leaked');
$check($row['id'] === 7 && $row['evidence'] === 'Source says public' && $row['revoked_at'] === null,
    'Review evidence or identity was lost');
$check(SnapshotPolicy::isPlaceAttachment(['mediable_type' => 'App\\Models\\Spot', 'mediable_id' => 10], [10 => true]),
    'Place attachment was lost');
$check(! SnapshotPolicy::isPlaceAttachment(['mediable_type' => 'App\\Models\\Event', 'mediable_id' => 10], [10 => true]),
    'Event attachment entered place snapshot');
$check(! SnapshotPolicy::isPlaceAttachment(['mediable_type' => 'App\\Models\\Spot', 'mediable_id' => 99], [10 => true]),
    'Attachment outside snapshot entered export');
try {
    SnapshotPolicy::columns('spots', ['id', 'name', 'unexpected_private_field']);
    throw new RuntimeException('Unknown column was silently exported');
} catch (DomainException) {
    $checks++;
}
try {
    SnapshotPolicy::columns('users', ['id']);
    throw new RuntimeException('Unknown table was silently exported');
} catch (DomainException) {
    $checks++;
}

$nested = SnapshotPolicy::sanitize('place_reconciliations', [
    'id' => 9, 'snapshot' => '{"7":{"actor":"private-reviewer","facts":{},"items":[]},"source":{"author":"Public artist"}}',
]);
$decoded = json_decode($nested['snapshot']);
$check($decoded->{'7'}->actor === 'local-catalogue-review', 'Nested numeric-key actor leaked');
$check($decoded->{'7'}->facts instanceof stdClass && $decoded->{'7'}->items === [], 'JSON object/list distinction changed');
$check($decoded->source->author === 'Public artist', 'Public photo attribution was removed');

echo json_encode(['status' => 'passed', 'checks' => $checks]).PHP_EOL;
