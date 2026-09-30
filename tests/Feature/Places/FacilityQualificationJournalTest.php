<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Models\PlaceFactCorrection;
use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use App\Places\ReviewPlaceFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $file = base_path('docs/places/production-release/FacilityQualificationJournal.php');
    if (is_file($file)) {
        require_once $file;
    }
});

function facilityQualificationFixture(int $number = 1): array
{
    $lat = 50.95 + ($number - 1) * .001;
    $spot = Spot::factory()->create(['name' => 'Playground', 'category' => 'playground',
        'source' => 'osm', 'source_id' => 'node/'.(987000 + $number), 'is_recommendable' => false,
        'lat' => $lat, 'lng' => 6.95, 'price_range' => null, 'tags' => ['access' => 'yes', 'leisure' => 'playground']]);
    app(RecordPlaceObservation::class)->record($spot, ['provider' => 'osm', 'provider_record_id' => $spot->source_id,
        'source_url' => 'https://www.openstreetmap.org/'.$spot->source_id, 'observed_at' => now()->toIso8601String(),
        'ingestion_key' => 'facility-test-'.$number, 'payload' => ['name' => null, 'access' => ['raw' => 'yes'],
            'fee' => ['raw' => null], 'location' => ['lat' => $lat, 'lng' => 6.95, 'kind' => 'source_node'], 'practical' => []]]);
    $proof = ['source_id' => $spot->source_id, 'visible' => true, 'version' => 3,
        'tags' => $spot->tags, 'geometry_verified' => true, 'lat' => $lat, 'lng' => 6.95];
    $record = ['source' => 'osm', 'source_id' => $spot->source_id, 'spot_id' => $spot->id,
        'category' => 'playground', 'checked_at' => now()->toIso8601String(), 'proof' => $proof,
        'proof_sha256' => FacilityQualificationJournal::hash($proof),
        'fingerprint' => app(ReviewPlaceFacts::class)->preview($spot->id, ['activity_discovery' => true])['fingerprint']];

    return [$record, $spot];
}

function facilityQualificationContext(array $records): array
{
    return ['database' => DB::selectOne('select current_database() as name')->name,
        'package_sha256' => FacilityQualificationJournal::hash($records), 'application_sha256' => hash('sha256', 'test-candidate'),
        'importer_sha256' => hash_file('sha256', base_path('docs/places/production-release/FacilityQualificationJournal.php'))];
}

test('facility journal qualifies explicit discovery and recovery retains native audit history', function () {
    [$record, $spot] = facilityQualificationFixture();
    $service = new FacilityQualificationJournal;
    $records = [$record];
    $context = facilityQualificationContext($records);
    $before = $service->snapshot([$spot->id]);
    $id = (string) Str::uuid();
    $receipt = $service->apply($id, $context, $records, $before, 'test-reviewer');
    $constraints = Constraints::fromArray(['window_start' => '2026-09-30T12:00:00+02:00', 'window_end' => '2026-09-30T16:00:00+02:00', 'categories' => ['playground']]);
    expect(array_column(app(CandidateRepository::class)->candidatesFor($constraints, 50.95, 6.95), 'id'))->toBe(["spot:{$spot->id}"])
        ->and($service->apply($id, $context, $records, $before, 'test-reviewer'))->toBe($receipt)
        ->and(PlaceFactCorrection::where('spot_id', $spot->id)->count())->toBe(1)
        ->and(app(PlaceFacts::class)->resolve($spot->fresh())['fee']['value'])->toBe('unknown');
    $revision = app(PlaceFacts::class)->revision();
    $recovery = (new FacilityQualificationJournal)->recover($id, $context, 'recovery-reviewer', 'Restore the held state after the isolated qualification rehearsal.');
    expect(app(CandidateRepository::class)->candidatesFor($constraints, 50.95, 6.95))->toBe([])
        ->and(PlaceFactCorrection::where('spot_id', $spot->id)->count())->toBe(2)
        ->and(PlaceFactCorrection::where('spot_id', $spot->id)->whereNull('revoked_at')->count())->toBe(0)
        ->and(app(PlaceFacts::class)->revision())->toBeGreaterThan($revision)
        ->and($service->snapshot([$spot->id])['spots'])->toBe($before['spots'])
        ->and($service->snapshot([$spot->id])['observations'])->toBe($before['observations'])
        ->and((new FacilityQualificationJournal)->recover($id, $context, 'recovery-reviewer', 'Restore the held state after the isolated qualification rehearsal.'))->toBe($recovery);
    expect(fn () => $service->apply($id, $context, $records, $before, 'test-reviewer'))->toThrow(DomainException::class);
});

test('qualification rejects source review and containment drift without changing the target', function (string $change) {
    [$record, $spot] = facilityQualificationFixture();
    $service = new FacilityQualificationJournal;
    $before = $service->snapshot([$spot->id]);
    if ($change === 'source') {
        $spot->update(['tags' => ['access' => 'private']]);
    } elseif ($change === 'parent') {
        $spot->update(['parent_spot_id' => Spot::factory()->create(['category' => 'park'])->id]);
    } else {
        $review = app(ReviewPlaceFacts::class);
        $preview = $review->preview($spot->id, ['name' => 'A later reviewed name']);
        $review->apply($spot->id, ['name' => 'A later reviewed name'], $preview['fingerprint'], 'A different source independently confirms this later name.', 'other-reviewer');
    }
    $state = $service->snapshot([$spot->id]);
    expect(fn () => $service->apply((string) Str::uuid(), facilityQualificationContext([$record]), [$record], $before, 'test-reviewer'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($state);
})->with(['source', 'parent', 'review']);

test('a later source observation invalidates the reviewed qualification package', function () {
    [$record, $spot] = facilityQualificationFixture();
    $service = new FacilityQualificationJournal;
    $before = $service->snapshot([$spot->id]);
    app(RecordPlaceObservation::class)->record($spot, ['provider' => 'osm', 'provider_record_id' => $spot->source_id,
        'source_url' => 'https://www.openstreetmap.org/'.$spot->source_id, 'observed_at' => now()->addSecond()->toIso8601String(),
        'ingestion_key' => 'later-source', 'payload' => ['access' => ['raw' => 'private']]]);
    $state = $service->snapshot([$spot->id]);
    expect(fn () => $service->apply((string) Str::uuid(), facilityQualificationContext([$record]), [$record], $before, 'test-reviewer'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($state);
});

test('invalid facility evidence refuses the whole batch and leaves no operation', function (string $change) {
    [$one, $first] = facilityQualificationFixture();
    [$two, $second] = facilityQualificationFixture(2);
    if ($change === 'proof') {
        $two['proof']['visible'] = false;
    } elseif ($change === 'expired') {
        $two['checked_at'] = now()->subDays(2)->toIso8601String();
    } elseif ($change === 'target') {
        $two['source_id'] = $one['source_id'];
    } else {
        $two = $one;
    }
    $records = [$one, $two];
    $service = new FacilityQualificationJournal;
    $before = $service->snapshot([$first->id, $second->id]);
    $id = (string) Str::uuid();
    $message = match ($change) {
        'proof' => 'Source or geometry evidence differs.',
        'expired' => 'Current source proof is required.',
        'target' => 'Source identity differs from reviewed target.',
        'duplicate' => 'Duplicate facility in package.',
    };
    expect(fn () => $service->apply($id, facilityQualificationContext($records), $records, $before, 'test-reviewer'))->toThrow(DomainException::class, $message);
    expect($service->snapshot([$first->id, $second->id]))->toBe($before)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
})->with(['proof', 'expired', 'target', 'duplicate']);

test('recovery refuses unrelated later reviews without overwriting them', function () {
    [$record, $spot] = facilityQualificationFixture();
    $service = new FacilityQualificationJournal;
    $context = facilityQualificationContext([$record]);
    $id = (string) Str::uuid();
    $service->apply($id, $context, [$record], $service->snapshot([$spot->id]), 'test-reviewer');
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($spot->id, ['name' => 'Later name']);
    $review->apply($spot->id, ['name' => 'Later name'], $preview['fingerprint'], 'This later reviewed name must be retained during recovery.', 'other-reviewer');
    $state = $service->snapshot([$spot->id]);
    expect(fn () => $service->recover($id, $context, 'test-reviewer', 'Attempt recovery after another reviewer changed this facility.'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($state);
});

test('qualification refuses the wrong database or a reused operation identity', function () {
    [$record, $spot] = facilityQualificationFixture();
    $service = new FacilityQualificationJournal;
    $context = facilityQualificationContext([$record]);
    $before = $service->snapshot([$spot->id]);
    $id = (string) Str::uuid();
    expect(fn () => $service->apply($id, [...$context, 'database' => 'expadu'], [$record], $before, 'test-reviewer'))->toThrow(DomainException::class);
    $service->apply($id, $context, [$record], $before, 'test-reviewer');
    expect(fn () => $service->apply($id, [...$context, 'application_sha256' => hash('sha256', 'different-code')], [$record], $before, 'test-reviewer'))->toThrow(DomainException::class);
});

test('qualification does not turn unsupported cost access or place categories into useful facilities', function (string $change) {
    [$record, $spot] = facilityQualificationFixture();
    if ($change === 'legacy_price') {
        $spot->update(['price_range' => '€']);
    } elseif ($change === 'unknown_access') {
        app(RecordPlaceObservation::class)->record($spot, ['provider' => 'osm', 'provider_record_id' => $spot->source_id,
            'source_url' => 'https://www.openstreetmap.org/'.$spot->source_id, 'observed_at' => now()->addSecond()->toIso8601String(),
            'ingestion_key' => 'unknown-access', 'payload' => ['access' => ['raw' => null]]]);
    } elseif ($change === 'unsupported_category') {
        $spot->update(['category' => 'museum']);
        $record['category'] = 'museum';
    } else {
        $record['proof']['geometry_verified'] = false;
        $record['proof_sha256'] = FacilityQualificationJournal::hash($record['proof']);
    }
    $record['fingerprint'] = app(ReviewPlaceFacts::class)->preview($spot->id, ['activity_discovery' => true])['fingerprint'];
    $service = new FacilityQualificationJournal;
    $before = $service->snapshot([$spot->id]);
    expect(fn () => $service->apply((string) Str::uuid(), facilityQualificationContext([$record]), [$record], $before, 'test-reviewer'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($before);
})->with(['legacy_price', 'unknown_access', 'unsupported_category', 'unverified_geometry']);

test('parent changes and new aliases prevent recovery from overwriting later identity work', function (string $change) {
    [$record, $spot] = facilityQualificationFixture();
    $parent = Spot::factory()->create(['category' => 'park']);
    $spot->update(['parent_spot_id' => $parent->id]);
    $record['fingerprint'] = app(ReviewPlaceFacts::class)->preview($spot->id, ['activity_discovery' => true])['fingerprint'];
    $service = new FacilityQualificationJournal;
    $context = facilityQualificationContext([$record]);
    $id = (string) Str::uuid();
    $service->apply($id, $context, [$record], $service->snapshot([$spot->id]), 'test-reviewer');
    if ($change === 'parent') {
        $parent->update(['is_active' => false]);
    } else {
        $alias = Spot::factory()->create(['category' => 'playground']);
        DB::table('spots')->where('id', $alias->id)->update(['canonical_spot_id' => $spot->id]);
    }
    $state = $service->snapshot([$spot->id]);
    expect(fn () => $service->recover($id, $context, 'test-reviewer', 'Attempt recovery after related destination identity changed.'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($state);
})->with(['parent', 'alias']);

test('a new overlapping source identity requires review before qualification', function () {
    [$record, $spot] = facilityQualificationFixture();
    $service = new FacilityQualificationJournal;
    $before = $service->snapshot([$spot->id]);
    Spot::factory()->create(['category' => 'playground', 'source' => 'osm', 'source_id' => 'node/111111',
        'lat' => 50.95, 'lng' => 6.95, 'is_active' => true]);
    expect(fn () => $service->apply((string) Str::uuid(), facilityQualificationContext([$record]), [$record], $before, 'test-reviewer'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($before);
});

test('fresh source proof must agree with effective access fee and map facts', function (string $field) {
    [$record, $spot] = facilityQualificationFixture();
    if ($field === 'map_point') {
        $spot->update(['lat' => 50.9502]);
        $record['proof']['lat'] = 50.9502;
    } else {
        $spot->update(['tags' => [...$spot->tags, $field => $field === 'access' ? 'private' : 'yes']]);
        $record['proof']['tags'] = $spot->fresh()->tags;
    }
    $record['proof_sha256'] = FacilityQualificationJournal::hash($record['proof']);
    $record['fingerprint'] = app(ReviewPlaceFacts::class)->preview($spot->id, ['activity_discovery' => true])['fingerprint'];
    $service = new FacilityQualificationJournal;
    $before = $service->snapshot([$spot->id]);
    $id = (string) Str::uuid();
    expect(fn () => $service->apply($id, facilityQualificationContext([$record]), [$record], $before, 'test-reviewer'))
        ->toThrow(DomainException::class, 'Current source proof disagrees with effective place facts.');
    expect($service->snapshot([$spot->id]))->toBe($before)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
})->with(['access', 'fee', 'map_point']);
