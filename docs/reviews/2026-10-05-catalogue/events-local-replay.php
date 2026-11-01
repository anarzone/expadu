<?php

use App\Jobs\ProcessEventJob;
use App\Jobs\ValidateMediaAssetJob;
use App\Models\Event;
use App\Models\User;
use App\Services\CologneServiceArea;
use Carbon\CarbonImmutable;
use Expadu\CatalogueReview\EventSnapshotQualification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

$startedAt = microtime(true);
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
require __DIR__.'/events-local-qualification.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['mode:', 'snapshot:', 'receipt:']);
$mode = $options['mode'] ?? 'preview';
$snapshot = $options['snapshot'] ?? '/private/tmp/expadu-events-catalogue-2026-10-05/official-feed.json';
$receipt = $options['receipt'] ?? null;
$committed = false;

function refuseUnless(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException('Refused: '.$reason);
    }
}

/** @return array<string, array{count: int, sha256: string}> */
function tableFingerprints(array $tables, bool $excludeSystemUser = false): array
{
    $result = [];
    foreach ($tables as $table) {
        refuseUnless(preg_match('/^[a-z_][a-z_0-9]*$/', $table) === 1, 'unexpected table name');
        $query = DB::table($table);
        if ($excludeSystemUser && $table === 'users') {
            $query->where('email', '!=', 'system@expadu.com');
        }
        $rows = $query->selectRaw('to_jsonb("'.$table.'")::text AS row_data')->get()
            ->map(fn ($row): string => hash('sha256', $row->row_data))->sort()->values()->all();
        $result[$table] = ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
    }

    return $result;
}

try {
    refuseUnless(in_array($mode, ['preview', 'apply'], true), 'mode must be preview or apply');
    refuseUnless($receipt === null || (is_string($receipt) && is_dir(dirname($receipt))
        && is_writable(dirname($receipt)) && ! file_exists($receipt)), 'receipt must be a new file in a writable existing directory');
    refuseUnless($app->environment('local'), 'only APP_ENV=local is allowed');
    $connection = DB::connection();
    refuseUnless($connection->getDriverName() === 'pgsql'
        && $connection->getDatabaseName() === 'expadu'
        && in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true), 'target must be the local expadu database');
    refuseUnless(is_file($snapshot) && hash_file('sha256', $snapshot) === EventSnapshotQualification::SNAPSHOT_SHA256, 'snapshot checksum does not match the captured official response');
    $payload = json_decode(file_get_contents($snapshot), true, flags: JSON_THROW_ON_ERROR);
    refuseUnless(($payload['success'] ?? null) === true && count($payload['items'] ?? []) === 276, 'unexpected snapshot payload');
    $capturedAt = CarbonImmutable::parse(EventSnapshotQualification::CAPTURED_AT)->setTimezone(config('app.timezone'));
    $now = CarbonImmutable::now('Europe/Berlin');
    refuseUnless($now->greaterThanOrEqualTo($capturedAt) && $now->lessThan($capturedAt->addHours(12)), 'snapshot is outside its 12-hour local replay window');
    $area = app(CologneServiceArea::class);
    $area->ensureOfficialPolygonsAvailable();

    $catalogueTables = ['events', 'venues', 'media_assets', 'media_attachments'];
    $tables = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"), 'tablename');
    $protectedTables = array_values(array_filter($tables, fn (string $table): bool => preg_match('/^(users$|user_|sessions$|password_reset_tokens$|personal_access_tokens$|notifications$|alerts$|push_|composer_|saved_|event_bookmarks$|event_reminders$|event_attendees$|trips$|trip_|location_|jobs$|failed_jobs$|job_batches$|spots$|spot_|veedels$|place_|tasks$|task_|bureaucracy_)/', $table) === 1));
    DB::beginTransaction();
    DB::statement("SET LOCAL lock_timeout = '5s'");
    $locked = array_values(array_unique([...$catalogueTables, ...$protectedTables]));
    foreach ($locked as $table) {
        refuseUnless(preg_match('/^[a-z_][a-z_0-9]*$/', $table) === 1, 'unexpected table name');
    }
    DB::statement('LOCK TABLE '.implode(', ', array_map(fn (string $table): string => '"'.$table.'"', $locked)).' IN SHARE ROW EXCLUSIVE MODE');
    refuseUnless(Event::query()->count() === 0, 'events must be empty; this tool never replaces an existing event inventory');

    $protectedBefore = tableFingerprints($protectedTables, true);
    $catalogueBefore = tableFingerprints($catalogueTables);
    $allUsersBefore = tableFingerprints(['users']);
    $existingOrganiser = User::query()->where('email', 'system@expadu.com')->first();
    $existingOrganiserHash = $existingOrganiser ? hash('sha256', $existingOrganiser->toJson()) : null;

    config(['events.classification.enabled' => false, 'cache.default' => 'array']);
    Bus::fake([ProcessEventJob::class, ValidateMediaAssetJob::class]);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake([
        'www.stadt-koeln.de/externe-dienste/open-data/events-od.php*' => Http::response($payload),
    ]);

    $qualification = [];
    foreach ($payload['items'] as $record) {
        $row = EventSnapshotQualification::qualify($record, $now, $area->contains(...));
        refuseUnless($row['source_uid'] !== null && ! isset($qualification[$row['source_uid']]), 'source identity is invalid or repeated');
        $qualification[$row['source_uid']] = $row;
    }

    DB::beginTransaction();
    User::query()->firstOrCreate(['email' => 'system@expadu.com'], [
        'name' => 'Expadu', 'password' => bcrypt(bin2hex(random_bytes(32))),
        'email_verified_at' => now(), 'city' => 'cologne',
    ]);
    refuseUnless(Artisan::call('events:import-official', ['--days' => 40]) === 0, 'native event import did not succeed');
    $importMetrics = trim(Artisan::output());
    refuseUnless(Event::query()->count() === count($qualification), 'native import did not retain every captured source record');

    $published = 0;
    $held = [];
    foreach (Event::query()->get() as $event) {
        $proof = $qualification[$event->source_uid] ?? null;
        refuseUnless($proof !== null && $event->source === 'stadt-koeln.de', 'unexpected imported identity');
        if ($proof['eligible']) {
            refuseUnless($event->starts_at->equalTo(CarbonImmutable::parse($proof['starts_at']))
                && $event->ends_at?->equalTo(CarbonImmutable::parse($proof['ends_at']))
                && $event->recurrence === null && $event->location !== null, 'native occurrence does not match the qualified source');
            $published++;
        } else {
            $held[] = ['source_uid' => $event->source_uid, 'reasons' => $proof['reasons']];
        }
        $event->update([
            'status' => $proof['eligible'] ? 'active' : 'hidden',
            'needs_review' => ! $proof['eligible'],
            'verified_at' => $capturedAt,
        ]);
    }

    refuseUnless(Artisan::call('events:link-venues', ['--limit' => 1000, '--geocode' => 0]) === 0, 'venue linking did not succeed');
    Http::assertSentCount(1);
    $newMedia = DB::table('media_assets')->where('provider', 'stadt-koeln');
    refuseUnless((clone $newMedia)->where('rights_status', '!=', 'pending')->count() === 0, 'municipal image rights changed');
    refuseUnless(tableFingerprints($protectedTables, true) === $protectedBefore, 'protected account or queue data changed');
    if ($existingOrganiserHash !== null) {
        refuseUnless(hash('sha256', $existingOrganiser->fresh()->toJson()) === $existingOrganiserHash, 'existing organiser changed');
    }
    refuseUnless(Event::query()->visible()->count() === $published, 'public visibility differs from qualified records');
    refuseUnless(Event::query()->get()->every(fn (Event $event): bool => $event->verified_at?->equalTo($capturedAt->startOfSecond()) === true), 'stored source verification time differs from the captured instant');

    $result = [
        'mode' => $mode, 'snapshot_sha256' => EventSnapshotQualification::SNAPSHOT_SHA256,
        'captured_at' => EventSnapshotQualification::CAPTURED_AT, 'qualified_at' => $now->toIso8601String(),
        'source_records' => count($qualification), 'published' => $published, 'held' => count($held),
        'holds' => $held, 'native_import_metrics' => json_decode($importMetrics, true),
        'organiser_created' => $existingOrganiser === null,
        'municipal_media_candidates' => (clone $newMedia)->count(),
        'municipal_media_approved' => 0, 'outbound_network_requests' => 0,
        'job_delivery_suppressed' => true, 'protected_before' => $protectedBefore,
        'protected_after' => tableFingerprints($protectedTables, true),
        'catalogue_before' => $catalogueBefore, 'catalogue_trial' => tableFingerprints($catalogueTables),
    ];
    if ($mode === 'preview') {
        DB::rollBack();
        $restoredCatalogue = tableFingerprints($catalogueTables);
        $restoredUsers = tableFingerprints(['users']);
        $restoredProtected = tableFingerprints($protectedTables, true);
        $rollbackDiff = [];
        foreach (['catalogue' => [$catalogueBefore, $restoredCatalogue], 'users' => [$allUsersBefore, $restoredUsers], 'protected' => [$protectedBefore, $restoredProtected]] as $group => [$before, $after]) {
            foreach ($before as $table => $fingerprint) {
                if ($fingerprint !== ($after[$table] ?? null)) {
                    $rollbackDiff[$group][$table] = ['before' => $fingerprint, 'after' => $after[$table] ?? null];
                }
            }
        }
        if ($rollbackDiff !== []) {
            fwrite(STDERR, json_encode(['rollback_differences' => $rollbackDiff], JSON_THROW_ON_ERROR)."\n");
        }
        refuseUnless($rollbackDiff === [], 'preview row rollback did not restore the original state');
        $result['rollback_verified'] = true;
        $result['rollback_verified_while_locks_held'] = true;
        $result['sequence_note'] = 'PostgreSQL identity sequences can advance during rollback; all checked rows are restored.';
        DB::rollBack();
    } else {
        DB::commit();
        DB::commit();
        $committed = true;
        $result['committed'] = true;
    }
    $result['elapsed_seconds'] = round(microtime(true) - $startedAt, 3);
    $encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    if (is_string($receipt)) {
        refuseUnless(file_put_contents($receipt, $encoded, LOCK_EX) === strlen($encoded), 'receipt could not be written');
        chmod($receipt, 0600);
    }
    echo $encoded;
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $message = $committed ? 'Replay committed; writing the receipt failed. Inspect the local catalogue before doing anything else.' : ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'Refused:')
        ? $exception->getMessage() : 'Replay failed; transaction rolled back ('.$exception::class.').');
    fwrite(STDERR, $message."\n");
    exit(1);
}
