<?php

use App\Models\MediaAttachment;
use App\Models\PlaceFactCorrection;
use App\Models\PlaceFactObservation;
use App\Models\Review;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__, 3).'/docs/places/staging-release/2026-09-29/verification/canary/GuardedCanary.php';

function canaryFixture(): array
{
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/70001', 'category' => 'cafe', 'name' => 'Original cafe', 'lat' => 50.94, 'lng' => 6.96, 'is_active' => true, 'is_recommendable' => true, 'tags' => ['amenity' => 'cafe']]);
    DB::statement("INSERT INTO veedels (name, bezirk, centroid_lat, centroid_lng, boundary, created_at, updated_at) VALUES ('Test area', 'Test district', 50.94, 6.96, ST_Multi(ST_GeomFromText('POLYGON((6.8 50.8,7.1 50.8,7.1 51.1,6.8 51.1,6.8 50.8))',4326)), NOW(), NOW())");
    $payload = ['name' => 'Original cafe', 'location' => ['lat' => 50.94, 'lng' => 6.96, 'kind' => 'source_node'], 'hours' => ['raw' => null], 'fee' => ['raw' => null, 'conditional' => null], 'access' => ['raw' => null, 'conditional' => null]];
    app(RecordPlaceObservation::class)->record($spot, ['provider' => 'osm', 'provider_record_id' => 'node/70001', 'source_url' => 'https://www.openstreetmap.org/node/70001', 'observed_at' => now()->subDays(10)->toIso8601String(), 'ingestion_key' => 'prior', 'payload' => $payload]);
    $record = ['key' => 'osm:node/70001', 'existing_id' => $spot->id, 'holds' => [], 'source' => 'osm', 'source_id' => 'node/70001', 'source_url' => 'https://www.openstreetmap.org/node/70001', 'category' => 'cafe', 'name' => 'Updated cafe', 'lat' => 50.95, 'lng' => 6.97, 'tags' => ['amenity' => 'cafe'], 'is_recommendable' => true, 'observed_at' => now()->subDay()->toIso8601String(), 'observation' => array_replace($payload, ['name' => 'Updated cafe', 'location' => ['lat' => 50.95, 'lng' => 6.97, 'kind' => 'source_node']]), 'address' => null, 'website' => null, 'phone' => null];
    $new = array_replace($record, ['key' => 'osm:node/70002', 'existing_id' => null, 'source_id' => 'node/70002', 'source_url' => 'https://www.openstreetmap.org/node/70002']);
    $baseline = [$spot->id => json_decode(DB::selectOne('SELECT row_to_json(s)::text AS raw FROM spots s WHERE id = ?', [$spot->id])->raw, true, flags: JSON_THROW_ON_ERROR)];

    return [$spot, [$record, $new], $baseline];
}

it('recovers refreshed facts and withdraws additions while preserving ids and history', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $helper = new GuardedCanary;
    $before = app(PlaceFacts::class)->resolve($spot->fresh());
    $receipt = $helper->apply($records, $baseline, str_repeat('a', 64));
    expect(Spot::findOrFail($spot->id)->name)->toBe('Updated cafe');
    $newId = $receipt['mapping']['osm:node/70002']['id'];
    $count = PlaceFactObservation::count();
    $recovery = $helper->recover($receipt, 'test-operator', 'Restore the reviewed canary to its previous source facts.');
    expect(Spot::findOrFail($spot->id)->name)->toBe('Original cafe')
        ->and(Spot::findOrFail($newId)->is_active)->toBeFalse()
        ->and(Spot::findOrFail($newId)->is_recommendable)->toBeFalse()
        ->and(PlaceFactObservation::count())->toBe($count + 1)
        ->and(app(PlaceFacts::class)->resolve($spot->fresh())['location']['map_point']['lat'])->toBe($before['location']['map_point']['lat'])
        ->and(app(PlaceFacts::class)->resolve($spot->fresh())['location']['map_point']['lng'])->toBe($before['location']['map_point']['lng']);
    expect($helper->recover($receipt, 'test-operator', 'Repeat the completed canary recovery without changes.', $recovery))->toBe($recovery);
    expect(PlaceFactObservation::count())->toBe($count + 1);
});

it('refuses refreshes without prior observations before applying any record', function () {
    [$spot, $records, $baseline] = canaryFixture();
    PlaceFactObservation::query()->delete();
    expect(fn () => (new GuardedCanary)->apply($records, $baseline, str_repeat('a', 64)))->toThrow(DomainException::class, 'prior source observation');
    expect(Spot::count())->toBe(1)->and($spot->fresh()->name)->toBe('Original cafe');
});

it('refuses stale recovery for the entire batch', function (string $kind) {
    [$spot, $records, $baseline] = canaryFixture();
    $helper = new GuardedCanary;
    $receipt = $helper->apply($records, $baseline, str_repeat('a', 64));
    if ($kind === 'spot') {
        $spot->fresh()->update(['name' => 'Later reviewed name']);
    } else {
        app(RecordPlaceObservation::class)->record($spot->fresh(), ['provider' => 'osm', 'provider_record_id' => 'node/70001', 'source_url' => 'https://www.openstreetmap.org/node/70001', 'observed_at' => now()->toIso8601String(), 'ingestion_key' => 'later', 'payload' => ['name' => 'Later source name']]);
    }
    $count = PlaceFactObservation::count();
    expect(fn () => $helper->recover($receipt, 'test-operator', 'Do not overwrite later source or reviewed changes.'))->toThrow(DomainException::class, 'changed since');
    expect(PlaceFactObservation::count())->toBe($count)->and(Spot::findOrFail($receipt['mapping']['osm:node/70002']['id'])->is_active)->toBeTrue();
})->with(['spot', 'observation']);

it('rolls back all recovery writes when source ordering cannot reproduce earlier facts', function () {
    [$spot, $records, $baseline] = canaryFixture();
    app(RecordPlaceObservation::class)->record($spot, ['provider' => 'other_open_source', 'provider_record_id' => 'fixture-1', 'source_url' => 'https://example.test/place', 'observed_at' => now()->subDays(2)->toIso8601String(), 'ingestion_key' => 'second-source', 'payload' => ['name' => 'Newer independent source name']]);
    $helper = new GuardedCanary;
    $receipt = $helper->apply($records, $baseline, str_repeat('a', 64));
    $after = $helper->snapshot(array_column($receipt['mapping'], 'id'));
    expect(fn () => $helper->recover($receipt, 'test-operator', 'Refuse a restore that would override a newer independent source.'))->toThrow(DomainException::class, 'resolved facts');
    expect($helper->snapshot(array_column($receipt['mapping'], 'id')))->toBe($after);
});

it('restores the spatial location and keeps the caller rollback boundary', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $beforeLocation = DB::table('spots')->where('id', $spot->id)->value('location');
    $helper = new GuardedCanary;
    $level = DB::transactionLevel();
    $receipt = $helper->apply($records, $baseline, str_repeat('a', 64));
    expect(DB::table('spots')->where('id', $spot->id)->value('location'))->not->toBe($beforeLocation);
    $helper->recover($receipt, 'test-operator', 'Restore the spatial value with the original coordinates.');
    expect(DB::table('spots')->where('id', $spot->id)->value('location'))->toBe($beforeLocation)->and(DB::transactionLevel())->toBe($level);
});

it('refuses recovery after a completed withdrawal was changed', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $helper = new GuardedCanary;
    $receipt = $helper->apply($records, $baseline, str_repeat('a', 64));
    $completed = $helper->recover($receipt, 'test-operator', 'Complete the canary withdrawal before an intervening change.');
    $spot->fresh()->update(['name' => 'Changed after recovery']);
    expect(fn () => $helper->recover($receipt, 'test-operator', 'Retry without overwriting a later change.', $completed))->toThrow(DomainException::class, 'changed since recovery');
});

require_once dirname(__DIR__, 3).'/docs/places/staging-release/2026-09-29/verification/canary/CanaryJournal.php';

it('reconstructs a missing file receipt from the atomic database journal without reimporting', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $operation = 'f593998b-f5c3-4b91-8819-9665f9d59a51';
    $context = array_fill_keys(['package_sha256', 'manifest_sha256', 'application_sha256', 'importer_sha256'], str_repeat('a', 64));
    $journal = new CanaryJournal;
    $receipt = $journal->apply($operation, $context, $records, $baseline, 'test-operator');
    $observations = PlaceFactObservation::count();
    // Simulate loss of the exported file; the DB transaction has its receipt.
    unset($receipt);
    $receipt = $journal->apply($operation, $context, $records, $baseline, 'test-operator');
    expect(PlaceFactObservation::count())->toBe($observations)->and(count($receipt['mapping']))->toBe(2);
    $recovery = $journal->recover($operation, $context, 'test-operator', 'Recover using the durable database operation record.');
    expect(DB::table('place_catalogue_operations')->value('state'))->toBe('recovered');
    expect($journal->recover($operation, $context, 'test-operator', 'Repeat an already completed recovery from its journal.'))->toEqual($recovery);
    expect(fn () => $journal->apply($operation, $context, $records, $baseline, 'test-operator'))->toThrow(DomainException::class, 'recovered');
});

it('rolls back the journal with the caller instead of leaving a false committed receipt', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $operation = 'f593998b-f5c3-4b91-8819-9665f9d59a51';
    $context = array_fill_keys(['package_sha256', 'manifest_sha256', 'application_sha256', 'importer_sha256'], str_repeat('a', 64));
    DB::beginTransaction();
    (new CanaryJournal)->apply($operation, $context, $records, $baseline, 'test-operator');
    DB::rollBack();
    expect(DB::table('place_catalogue_operations')->count())->toBe(0)->and(Spot::count())->toBe(1)->and($spot->fresh()->name)->toBe('Original cafe');
    expect(fn () => (new CanaryJournal)->recover($operation, $context, 'test-operator', 'No journal means no committed operation to recover.'))->toThrow(DomainException::class, 'No committed operation');
});

it('holds reviewed access fee and activity qualifications before import', function (string $field) {
    [$spot, $records, $baseline] = canaryFixture();
    PlaceFactCorrection::factory()->create(['spot_id' => $spot->id, 'field' => $field]);
    expect(fn () => (new GuardedCanary)->apply($records, $baseline, str_repeat('a', 64)))->toThrow(DomainException::class, 'reviewed qualification');
    expect(Spot::count())->toBe(1)->and($spot->fresh()->name)->toBe('Original cafe');
})->with(['access', 'fee', 'activity_discovery']);

it('refuses future source observations before import', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $records[0]['observed_at'] = now()->addDay()->toIso8601String();
    expect(fn () => (new GuardedCanary)->apply($records, $baseline, str_repeat('a', 64)))->toThrow(DomainException::class, 'Future or equal');
    expect(Spot::count())->toBe(1);
});

it('does not append a restore for a source stream the import did not change', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $records[0]['observation'] = PlaceFactObservation::where('spot_id', $spot->id)->sole()->payload;
    $helper = new GuardedCanary;
    $receipt = $helper->apply($records, $baseline, str_repeat('a', 64));
    $observations = PlaceFactObservation::count();
    $recovery = $helper->recover($receipt, 'test-operator', 'Do not create unnecessary restore events for unchanged streams.');
    expect($recovery['restored_source_streams'])->toBe(0)->and(PlaceFactObservation::count())->toBe($observations);
});

it('refuses recovery when a reviewed fact was added after import', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $helper = new GuardedCanary;
    $receipt = $helper->apply($records, $baseline, str_repeat('a', 64));
    PlaceFactCorrection::factory()->create(['spot_id' => $spot->id, 'field' => 'name']);
    expect(fn () => $helper->recover($receipt, 'test-operator', 'Preserve a later reviewed correction rather than recovering.'))->toThrow(DomainException::class, 'changed since');
    expect(Spot::findOrFail($receipt['mapping']['osm:node/70002']['id'])->is_active)->toBeTrue();
});

it('rehearses recovery before commit then returns exactly to the imported state', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $operation = 'f593998b-f5c3-4b91-8819-9665f9d59a51';
    $context = array_fill_keys(['package_sha256', 'manifest_sha256', 'application_sha256', 'importer_sha256'], str_repeat('a', 64));
    $journal = new CanaryJournal;
    $receipt = $journal->apply($operation, $context, $records, $baseline, 'test-operator');
    $level = DB::transactionLevel();
    $proof = $journal->rehearseRecovery($operation, $context, 'test-operator', 'Verify recovery before committing the exact canary operation.');
    expect($proof['applied_state_restored'])->toBeTrue()->and($proof['caller_boundary_retained'])->toBeTrue()->and($proof['retained_alias_history_changes'])->toBe(1)
        ->and(DB::transactionLevel())->toBe($level)->and((new GuardedCanary)->snapshot(array_column($receipt['mapping'], 'id')))->toBe($receipt['after']);
});

it('preserves later media reviews and saved and visited references during recovery', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $helper = new GuardedCanary;
    $receipt = $helper->apply($records, $baseline, str_repeat('a', 64));
    $new = Spot::findOrFail($receipt['mapping']['osm:node/70002']['id']);
    $review = Review::factory()->create(['spot_id' => $new->id]);
    $saved = SpotFeedback::factory()->create(['spot_id' => $new->id]);
    $visited = SpotFeedback::factory()->been()->create(['spot_id' => $spot->id]);
    $attachment = MediaAttachment::factory()->accepted()->create(['mediable_type' => Spot::class, 'mediable_id' => $new->id]);
    $expected = [$review->fresh()->getRawOriginal(), $saved->fresh()->getRawOriginal(), $visited->fresh()->getRawOriginal(), $attachment->fresh()->getRawOriginal(), $attachment->mediaAsset->fresh()->getRawOriginal()];
    $recovery = $helper->recover($receipt, 'test-operator', 'Retain saved references and independent media when withdrawing the canary.');
    expect([$review->fresh()->getRawOriginal(), $saved->fresh()->getRawOriginal(), $visited->fresh()->getRawOriginal(), $attachment->fresh()->getRawOriginal(), $attachment->mediaAsset->fresh()->getRawOriginal()])->toBe($expected)
        ->and($recovery['alias_deltas'][$spot->id]['after'])->toContain('Updated cafe')
        ->and($spot->fresh()->name)->toBe('Original cafe');
});

it('rejects a corrupt journal receipt before any recovery writes', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $operation = 'f593998b-f5c3-4b91-8819-9665f9d59a51';
    $context = array_fill_keys(['package_sha256', 'manifest_sha256', 'application_sha256', 'importer_sha256'], str_repeat('a', 64));
    $journal = new CanaryJournal;
    $receipt = $journal->apply($operation, $context, $records, $baseline, 'test-operator');
    $receipt['before_facts'][$spot->id]['name']['value'] = 'Altered receipt';
    DB::table('place_catalogue_operations')->where('id', $operation)->update(['receipt' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
    $count = PlaceFactObservation::count();
    expect(fn () => $journal->recover($operation, $context, 'test-operator', 'Reject an edited receipt without its matching checksum.'))->toThrow(DomainException::class, 'checksum');
    expect(PlaceFactObservation::count())->toBe($count)->and($spot->fresh()->name)->toBe('Updated cafe');
});

it('refuses to drop an operation journal containing recovery evidence', function () {
    [$spot, $records, $baseline] = canaryFixture();
    $operation = 'f593998b-f5c3-4b91-8819-9665f9d59a51';
    $context = array_fill_keys(['package_sha256', 'manifest_sha256', 'application_sha256', 'importer_sha256'], str_repeat('a', 64));
    (new CanaryJournal)->apply($operation, $context, $records, $baseline, 'test-operator');
    $migration = require base_path('database/migrations/2026_09_29_155011_create_place_catalogue_operations_table.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'recovery evidence');
    expect(DB::table('place_catalogue_operations')->where('id', $operation)->exists())->toBeTrue();
});
