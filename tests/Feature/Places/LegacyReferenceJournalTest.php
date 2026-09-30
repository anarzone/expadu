<?php

use App\Composer\CandidateRepository;
use App\Models\MediaAttachment;
use App\Models\PlaceFactCorrection;
use App\Models\Review;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Places\PlaceFacts;
use App\Places\PlaceIdentity;
use App\Places\ReconcilePlace;
use App\Places\RecordPlaceObservation;
use App\Places\ReviewPlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $file = base_path('docs/places/production-release/LegacyReferenceJournal.php');
    if (is_file($file)) {
        require_once $file;
    }
});

function legacyReferenceFixture(int $number = 1): array
{
    $lat = 50.95 + $number * .001;
    $attributes = ['name' => 'Reviewed cafe '.$number, 'category' => 'cafe', 'lat' => $lat, 'lng' => 6.95,
        'tags' => ['amenity' => 'cafe', 'access' => 'yes'], 'rating' => 4.7];
    $alias = Spot::factory()->create([...$attributes, 'source' => null, 'source_id' => null, 'is_recommendable' => false]);
    $canonical = Spot::factory()->create([...$attributes, 'source' => 'osm', 'source_id' => 'node/'.(988000 + $number)]);
    app(RecordPlaceObservation::class)->record($canonical, ['provider' => 'osm', 'provider_record_id' => $canonical->source_id,
        'source_url' => 'https://www.openstreetmap.org/'.$canonical->source_id, 'observed_at' => now()->toIso8601String(),
        'ingestion_key' => 'legacy-journal-'.$number, 'payload' => ['name' => $canonical->name, 'access' => ['raw' => 'yes'],
            'location' => ['lat' => $lat, 'lng' => 6.95, 'kind' => 'source_node']]]);
    $proof = ['source_id' => $canonical->source_id, 'visible' => true, 'version' => 3, 'tags' => $canonical->tags,
        'geometry_verified' => true, 'lat' => $lat, 'lng' => 6.95];
    $record = ['alias_id' => $alias->id, 'canonical_id' => $canonical->id, 'source_id' => $canonical->source_id,
        'checked_at' => now()->toIso8601String(), 'proof' => $proof, 'proof_sha256' => LegacyReferenceJournal::hash($proof),
        'identity_fingerprint' => app(ReconcilePlace::class)->preview($alias->id, $canonical->id)['fingerprint']];

    return [$record, $alias, $canonical];
}

function legacyReferenceContext(array $records): array
{
    return ['database' => DB::selectOne('select current_database() as name')->name,
        'package_sha256' => LegacyReferenceJournal::hash($records), 'application_sha256' => hash('sha256', 'test-candidate'),
        'importer_sha256' => hash_file('sha256', base_path('docs/places/production-release/LegacyReferenceJournal.php'))];
}

test('legacy journal resolves older references and recovers from durable state without deleting audit', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    [$record, $alias, $canonical] = legacyReferenceFixture();
    $service = new LegacyReferenceJournal;
    $context = legacyReferenceContext([$record]);
    $before = $service->snapshot([$alias->id, $canonical->id]);
    $id = (string) Str::uuid();
    $receipt = $service->apply($id, $context, [$record], $before, 'test-reviewer');
    expect(app(PlaceIdentity::class)->canonicalIds([$alias->id])[$alias->id])->toBe($canonical->id)
        ->and((new LegacyReferenceJournal)->apply($id, $context, [$record], $before, 'test-reviewer'))->toBe($receipt);
    $this->getJson('/api/places/'.$alias->id)->assertSuccessful()->assertJsonPath('data.id', $canonical->id)
        ->assertJsonPath('data.recommendation_status', 'available');
    expect(array_column(app(CandidateRepository::class)->byIds(['spot:'.$alias->id], CarbonImmutable::now()), 'id'))->toBe(['spot:'.$canonical->id]);
    $revision = app(PlaceFacts::class)->revision();
    $recovery = (new LegacyReferenceJournal)->recover($id, $context, 'recovery-reviewer', 'Restore the original held reference after this isolated trial.');
    expect($alias->fresh()->canonical_spot_id)->toBeNull()
        ->and($alias->fresh()->is_recommendable)->toBeFalse()
        ->and($canonical->fresh()->rating)->toBe(4.7)
        ->and(DB::table('place_reconciliations')->count())->toBe(1)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->value('state'))->toBe('recovered')
        ->and(app(PlaceFacts::class)->revision())->toBeGreaterThan($revision)
        ->and($service->snapshot([$alias->id, $canonical->id])['observations'])->toBe($before['observations'])
        ->and($service->snapshot([$alias->id, $canonical->id])['corrections'])->toBe($before['corrections'])
        ->and((new LegacyReferenceJournal)->recover($id, $context, 'recovery-reviewer', 'Restore the original held reference after this isolated trial.'))->toBe($recovery);
    $this->getJson('/api/places/'.$alias->id)->assertSuccessful()->assertJsonPath('data.id', $alias->id)
        ->assertJsonPath('data.recommendation_status', 'unavailable');
    expect(app(CandidateRepository::class)->byIds(['spot:'.$alias->id], CarbonImmutable::now()))->toBe([]);
    expect(fn () => $service->apply($id, $context, [$record], $before, 'test-reviewer'))->toThrow(DomainException::class);
    $record['identity_fingerprint'] = app(ReconcilePlace::class)->preview($alias->id, $canonical->id)['fingerprint'];
    expect(fn () => $service->apply((string) Str::uuid(), legacyReferenceContext([$record]), [$record], $service->snapshot([$alias->id, $canonical->id]), 'test-reviewer'))
        ->toThrow(DomainException::class, 'Alias already has reconciliation history.');
});

test('legacy recovery preserves source fields reviews feedback and media rows', function () {
    [$record, $alias, $canonical] = legacyReferenceFixture();
    Review::factory()->create(['spot_id' => $alias->id, 'rating' => 3]);
    Review::factory()->create(['spot_id' => $canonical->id, 'rating' => 5]);
    SpotFeedback::factory()->create(['spot_id' => $alias->id]);
    MediaAttachment::factory()->accepted()->create(['mediable_type' => Spot::class, 'mediable_id' => $alias->id]);
    $record['identity_fingerprint'] = app(ReconcilePlace::class)->preview($alias->id, $canonical->id)['fingerprint'];
    $service = new LegacyReferenceJournal;
    $before = $service->snapshot([$alias->id, $canonical->id]);
    $canonicalBefore = $canonical->fresh()->getRawOriginal();
    $id = (string) Str::uuid();
    $context = legacyReferenceContext([$record]);
    $service->apply($id, $context, [$record], $before, 'test-reviewer');
    $service->recover($id, $context, 'test-reviewer', 'Recover only the reviewed link and leave every user and media record intact.');
    $after = $service->snapshot([$alias->id, $canonical->id]);
    foreach (['reviews', 'feedback', 'attachments', 'assets', 'observations', 'corrections', 'references', 'destination_reviews'] as $field) {
        expect($after[$field])->toBe($before[$field]);
    }
    expect($canonical->fresh()->getRawOriginal())->toBe($canonicalBefore);
});

test('invalid second legacy record refuses the whole batch for its intended reason', function (string $change) {
    [$one, $firstAlias, $firstCanonical] = legacyReferenceFixture();
    [$two, $secondAlias, $secondCanonical] = legacyReferenceFixture(2);
    $message = match ($change) {
        'expired', 'future' => 'Current source proof is required.',
        'timestamp_blank', 'timestamp_relative', 'timestamp_today', 'timestamp_offset_relative', 'timestamp_malformed', 'timestamp_no_timezone', 'timestamp_invalid_date' => 'An absolute source timestamp with timezone is required.',
        'identity' => 'Native identity preview changed.',
        'source' => 'Source identity differs from reviewed pair.',
        'duplicate', 'cross_used' => 'Pairs must have disjoint positive IDs.',
        default => 'Current source or geometry proof differs.',
    };
    match ($change) {
        'expired' => $two['checked_at'] = now()->subDays(2)->toIso8601String(),
        'future' => $two['checked_at'] = now()->addDay()->toIso8601String(),
        'timestamp_blank' => $two['checked_at'] = '',
        'timestamp_relative' => $two['checked_at'] = 'now',
        'timestamp_today' => $two['checked_at'] = 'today',
        'timestamp_offset_relative' => $two['checked_at'] = '-1 hour',
        'timestamp_malformed' => $two['checked_at'] = 'not-a-timestamp',
        'timestamp_no_timezone' => $two['checked_at'] = now()->format('Y-m-d\TH:i:s'),
        'timestamp_invalid_date' => $two['checked_at'] = '2026-09-31T12:00:00+00:00',
        'identity' => $two['identity_fingerprint'] = str_repeat('a', 64),
        'source' => $two['source_id'] = 'node/999999999',
        'duplicate' => $two = $one,
        'cross_used' => $two['canonical_id'] = $one['alias_id'],
        'point' => $two['proof']['lat'] += .0002,
        'geometry' => $two['proof']['geometry_verified'] = false,
        default => $two['proof']['visible'] = false,
    };
    $two['proof_sha256'] = LegacyReferenceJournal::hash($two['proof']);
    $records = [$one, $two];
    $service = new LegacyReferenceJournal;
    $ids = [$firstAlias->id, $firstCanonical->id, $secondAlias->id, $secondCanonical->id];
    $before = $service->snapshot($ids);
    $id = (string) Str::uuid();
    expect(fn () => $service->apply($id, legacyReferenceContext($records), $records, $before, 'test-reviewer'))->toThrow(DomainException::class, $message);
    expect($service->snapshot($ids))->toBe($before)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
})->with(['expired', 'future', 'identity', 'source', 'duplicate', 'cross_used', 'point', 'geometry', 'visibility',
    'timestamp_blank', 'timestamp_relative', 'timestamp_today', 'timestamp_offset_relative', 'timestamp_malformed', 'timestamp_no_timezone', 'timestamp_invalid_date']);

test('unsupported alias history and references remain held even with a fresh preview', function (string $change) {
    [$record, $alias, $canonical] = legacyReferenceFixture();
    if ($change === 'observation') {
        app(RecordPlaceObservation::class)->record($alias, ['provider' => 'legacy', 'provider_record_id' => 'held-1',
            'source_url' => 'https://example.test/held', 'observed_at' => now()->toIso8601String(),
            'ingestion_key' => 'held-history', 'payload' => ['name' => $alias->name]]);
    } elseif ($change === 'correction') {
        PlaceFactCorrection::factory()->create(['spot_id' => $alias->id, 'field' => 'name', 'revoked_at' => now()]);
    } else {
        Spot::factory()->create(['parent_spot_id' => $alias->id]);
    }
    $record['identity_fingerprint'] = app(ReconcilePlace::class)->preview($alias->id, $canonical->id)['fingerprint'];
    $service = new LegacyReferenceJournal;
    $before = $service->snapshot([$alias->id, $canonical->id]);
    expect(fn () => $service->apply((string) Str::uuid(), legacyReferenceContext([$record]), [$record], $before, 'test-reviewer'))
        ->toThrow(DomainException::class, 'Alias history or incoming references require separate recovery handling.');
    expect($service->snapshot([$alias->id, $canonical->id]))->toBe($before);
})->with(['observation', 'correction', 'child']);

test('later source identity review saved-reference or media drift prevents legacy recovery', function (string $change) {
    [$record, $alias, $canonical] = legacyReferenceFixture();
    $service = new LegacyReferenceJournal;
    $context = legacyReferenceContext([$record]);
    $id = (string) Str::uuid();
    $service->apply($id, $context, [$record], $service->snapshot([$alias->id, $canonical->id]), 'test-reviewer');
    if ($change === 'source') {
        $canonical->update(['tags' => ['access' => 'private']]);
    } elseif ($change === 'observation') {
        app(RecordPlaceObservation::class)->record($canonical, ['provider' => 'osm', 'provider_record_id' => $canonical->source_id,
            'source_url' => 'https://www.openstreetmap.org/'.$canonical->source_id, 'observed_at' => now()->addSecond()->toIso8601String(),
            'ingestion_key' => 'later-evidence', 'payload' => ['access' => ['raw' => 'private']]]);
    } elseif ($change === 'review') {
        $review = app(ReviewPlaceFacts::class);
        $preview = $review->preview($canonical->id, ['name' => 'Later reviewed name']);
        $review->apply($canonical->id, ['name' => 'Later reviewed name'], $preview['fingerprint'], 'This independent later review must not be overwritten by recovery.', 'other-reviewer');
    } elseif ($change === 'saved') {
        SpotFeedback::factory()->create(['spot_id' => $alias->id]);
    } elseif ($change === 'media') {
        MediaAttachment::factory()->accepted()->create(['mediable_type' => Spot::class, 'mediable_id' => $canonical->id]);
    } elseif ($change === 'family') {
        $other = Spot::factory()->create();
        DB::table('spots')->where('id', $other->id)->update(['canonical_spot_id' => $canonical->id]);
    } else {
        Spot::factory()->create(['parent_spot_id' => $canonical->id]);
    }
    $state = $service->snapshot([$alias->id, $canonical->id]);
    expect(fn () => $service->recover($id, $context, 'test-reviewer', 'Refuse recovery after a later protected source or reference change.'))
        ->toThrow(DomainException::class, 'Later identity source or reference changes prevent recovery.');
    expect($service->snapshot([$alias->id, $canonical->id]))->toBe($state)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->value('state'))->toBe('applied');
})->with(['source', 'observation', 'review', 'saved', 'media', 'family', 'child']);

test('legacy journal refuses wrong context and a corrupted durable receipt', function () {
    [$record, $alias, $canonical] = legacyReferenceFixture();
    $service = new LegacyReferenceJournal;
    $context = legacyReferenceContext([$record]);
    $before = $service->snapshot([$alias->id, $canonical->id]);
    $id = (string) Str::uuid();
    foreach (['database' => 'expadu', 'package_sha256' => str_repeat('a', 64), 'importer_sha256' => str_repeat('a', 64)] as $field => $value) {
        expect(fn () => $service->apply($id, [...$context, $field => $value], [$record], $before, 'test-reviewer'))->toThrow(DomainException::class);
    }
    $service->apply($id, $context, [$record], $before, 'test-reviewer');
    expect(fn () => $service->apply($id, $context, [$record], $before, 'other-reviewer'))->toThrow(DomainException::class);
    $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
    $receipt = json_decode($row->receipt, true, flags: JSON_THROW_ON_ERROR);
    $receipt['records'] = 999;
    DB::table('place_catalogue_operations')->where('id', $id)->update(['receipt' => json_encode($receipt, JSON_THROW_ON_ERROR)]);
    expect(fn () => $service->recover($id, $context, 'test-reviewer', 'Refuse recovery using a corrupted journal receipt.'))->toThrow(DomainException::class, 'Journal checksum differs.');
});

test('legacy recovery replay requires the same reason actor and current state', function () {
    [$record, $alias, $canonical] = legacyReferenceFixture();
    $service = new LegacyReferenceJournal;
    $context = legacyReferenceContext([$record]);
    $id = (string) Str::uuid();
    $service->apply($id, $context, [$record], $service->snapshot([$alias->id, $canonical->id]), 'test-reviewer');
    $reason = 'Restore the original held state and retain all identity audit events.';
    $service->recover($id, $context, 'test-reviewer', $reason);
    expect(fn () => $service->recover($id, $context, 'other-reviewer', $reason))->toThrow(DomainException::class);
    expect(fn () => $service->recover($id, $context, 'test-reviewer', 'A different recovery reason must not replay the earlier operation.'))->toThrow(DomainException::class);
    $alias->update(['name' => 'Changed after recovery']);
    expect(fn () => $service->recover($id, $context, 'test-reviewer', $reason))->toThrow(DomainException::class);
});

test('missing current source proof is explicitly held without a partial operation', function (string $missing) {
    [$record, $alias, $canonical] = legacyReferenceFixture();
    if ($missing === 'proof') {
        unset($record['proof']);
    } else {
        unset($record['proof'][$missing]);
        $record['proof_sha256'] = LegacyReferenceJournal::hash($record['proof']);
    }
    $service = new LegacyReferenceJournal;
    $before = $service->snapshot([$alias->id, $canonical->id]);
    $id = (string) Str::uuid();
    expect(fn () => $service->apply($id, legacyReferenceContext([$record]), [$record], $before, 'test-reviewer'))
        ->toThrow(DomainException::class, 'Complete current source proof is required.');
    expect($service->snapshot([$alias->id, $canonical->id]))->toBe($before)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
})->with(['proof', 'version', 'tags', 'lat', 'geometry_verified']);

test('legacy package bounds and duplicate source identities are explicit holds', function () {
    [$one, $firstAlias, $firstCanonical] = legacyReferenceFixture();
    [$two, $secondAlias, $secondCanonical] = legacyReferenceFixture(2);
    $service = new LegacyReferenceJournal;
    $records = array_fill(0, 501, $one);
    expect(fn () => $service->apply((string) Str::uuid(), legacyReferenceContext($records), $records, [], 'test-reviewer'))
        ->toThrow(DomainException::class, 'Use 1 to 500 reviewed pairs.');
    expect(fn () => $service->apply((string) Str::uuid(), legacyReferenceContext([]), [], [], 'test-reviewer'))
        ->toThrow(DomainException::class, 'Use 1 to 500 reviewed pairs.');
    $two['source_id'] = $one['source_id'];
    $records = [$one, $two];
    $before = $service->snapshot([$firstAlias->id, $firstCanonical->id, $secondAlias->id, $secondCanonical->id]);
    expect(fn () => $service->apply((string) Str::uuid(), legacyReferenceContext($records), $records, $before, 'test-reviewer'))
        ->toThrow(DomainException::class, 'Duplicate source identity in package.');
    expect($service->snapshot([$firstAlias->id, $firstCanonical->id, $secondAlias->id, $secondCanonical->id]))->toBe($before);
});

test('unsupported transaction isolation is refused before any legacy link', function (string $isolation) {
    DB::selectOne('SELECT 1 AS initialize_lazy_test_database');
    expect(DB::transactionLevel())->toBe(1);
    DB::rollBack();
    DB::beginTransaction();
    DB::statement('SET TRANSACTION ISOLATION LEVEL '.$isolation);
    [$record, $alias, $canonical] = legacyReferenceFixture();
    $service = new LegacyReferenceJournal;
    $before = $service->snapshot([$alias->id, $canonical->id]);
    $id = (string) Str::uuid();
    expect(fn () => $service->apply($id, legacyReferenceContext([$record]), [$record], $before, 'test-reviewer'))
        ->toThrow(DomainException::class, 'Read committed isolation is required for current drift checks.');
    expect($service->snapshot([$alias->id, $canonical->id]))->toBe($before)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
})->with(['REPEATABLE READ', 'SERIALIZABLE']);
