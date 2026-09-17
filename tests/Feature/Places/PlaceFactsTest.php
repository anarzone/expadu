<?php

use App\Models\PlaceFactCorrection;
use App\Models\PlaceFactObservation;
use App\Models\Spot;
use App\Models\User;
use App\Places\PlaceFacts;
use App\Places\ReconcilePlace;
use App\Places\RecordPlaceObservation;
use App\Places\ReviewPlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->onboarded()->create());
});

test('an explicitly private place is excluded from general recommendations', function () {
    $private = Spot::factory()->create([
        'name' => 'Private courtyard playground',
        'category' => 'playground',
        'tags' => ['access' => 'private'],
        'lat' => 50.95,
        'lng' => 6.95,
    ]);

    $ids = collect($this->getJson('/api/places')->assertSuccessful()->json('data'))->pluck('id');

    expect($ids)->not->toContain($private->id);
});

test('a private playground does not inherit free or open claims from its category', function () {
    $private = Spot::factory()->create([
        'name' => 'Private courtyard playground',
        'category' => 'playground',
        'tags' => ['access' => 'private'],
        'lat' => 50.95,
        'lng' => 6.95,
    ]);

    $this->getJson("/api/places/{$private->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.open_now', null)
        ->assertJsonPath('data.opening_hours_text', null)
        ->assertJsonPath('data.price_text', null);
});

test('the facts audit reports reproducible aggregate gaps and public place ids', function () {
    config()->set('app.commit', 'exp69-test-commit');
    $generic = Spot::factory()->create([
        'name' => 'Spielplatz',
        'category' => 'playground',
        'description' => null,
        'tags' => ['access' => 'private'],
        'lat' => null,
        'lng' => null,
        'source' => null,
        'source_id' => null,
    ]);
    $documented = Spot::factory()->create([
        'name' => 'Documented park',
        'category' => 'park',
        'description' => 'A source-backed description.',
        'tags' => ['access' => 'yes', 'fee' => 'no', 'opening_hours' => '24/7'],
        'lat' => 50.95,
        'lng' => 6.95,
        'source' => 'osm',
        'source_id' => 'way/123',
        'website' => 'https://example.test/park',
        'phone' => '+49 221 1234',
    ]);
    $club = Spot::factory()->create([
        'name' => 'Members tennis club',
        'category' => 'tennis',
        'description' => null,
        'tags' => ['access' => 'members', 'fee' => 'yes'],
        'lat' => 50.96,
        'lng' => 6.96,
        'source' => 'osm',
        'source_id' => 'node/456',
        'website' => null,
        'phone' => null,
    ]);

    expect(Artisan::call('places:audit-facts'))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['commit'])->toBe('exp69-test-commit')
        ->and($report['denominator'])->toBe(3)
        ->and($report['counts'])->toMatchArray([
            'generic_names' => 1,
            'missing_coordinates' => 1,
            'missing_provenance' => 1,
            'restricted_access' => 2,
            'fee_evidence' => 2,
            'hours_evidence' => 1,
            'with_website' => 1,
            'with_phone' => 1,
            'with_description' => 1,
        ])
        ->and($report['place_ids']['generic_names'])->toBe([$generic->id])
        ->and($report['place_ids']['restricted_access'])->toBe([$generic->id, $club->id])
        ->and($report['place_ids']['fee_evidence'])->toBe([$documented->id, $club->id]);
});

test('the facts audit refuses output paths outside the application root', function () {
    $outside = dirname(base_path()).'/exp69-outside-audit.json';
    @unlink($outside);

    $this->artisan('places:audit-facts', ['--output' => '../exp69-outside-audit.json'])
        ->assertFailed();

    expect($outside)->not->toBeFile();
});

test('replayed and consecutively unchanged observations do not create history or revision churn', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/123']);
    $recorder = app(RecordPlaceObservation::class);
    $first = [
        'provider' => 'osm',
        'provider_record_id' => 'node/123',
        'source_url' => 'https://www.openstreetmap.org/node/123',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-20260917-a-node-123',
        'payload' => ['name' => 'Source name', 'access' => ['raw' => 'yes']],
    ];

    $recorder->record($spot, $first);
    $recorder->record($spot, $first);
    $recorder->record($spot, [...$first, 'ingestion_key' => 'osm-20260917-b-node-123', 'observed_at' => '2026-09-17T11:00:00+00:00']);

    expect(PlaceFactObservation::query()->count())->toBe(1)
        ->and(app(PlaceFacts::class)->revision())->toBe(1);
});

test('source observations reject malformed coordinates urls and unsupported facts', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/124']);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/124',
        'source_url' => 'https://www.openstreetmap.org/node/124',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-invalid-124', // gitleaks:allow -- deterministic test fixture, not a credential
    ];

    expect(fn () => $recorder->record($spot, [
        ...$base,
        'payload' => ['location' => ['lat' => 91, 'lng' => 6.95, 'kind' => 'source_node']],
    ]))->toThrow(DomainException::class)
        ->and(fn () => $recorder->record($spot, [
            ...$base,
            'ingestion_key' => 'osm-invalid-url-124',
            'payload' => ['contact' => ['website' => 'javascript:alert(1)']],
        ]))->toThrow(DomainException::class)
        ->and(fn () => $recorder->record($spot, [
            ...$base,
            'ingestion_key' => 'osm-invalid-field-124',
            'payload' => ['rating' => 5],
        ]))->toThrow(DomainException::class)
        ->and(PlaceFactObservation::query()->count())->toBe(0);
});

test('an A to B to A source reversion remains the latest observation', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/456']);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/456',
        'source_url' => 'https://www.openstreetmap.org/node/456',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-20260917-a-node-456',
        'payload' => ['name' => 'Name A'],
    ];

    $recorder->record($spot, $base);
    $recorder->record($spot, [...$base, 'ingestion_key' => 'osm-20260917-b-node-456', 'observed_at' => '2026-09-17T11:00:00+00:00', 'payload' => ['name' => 'Name B']]);
    $recorder->record($spot, [...$base, 'ingestion_key' => 'osm-20260917-c-node-456', 'observed_at' => '2026-09-17T12:00:00+00:00']);

    expect(PlaceFactObservation::query()->count())->toBe(3)
        ->and(PlaceFactObservation::query()->latest('id')->firstOrFail()->payload['name'])->toBe('Name A')
        ->and(app(PlaceFacts::class)->revision())->toBe(3);
});

test('a source restore is append only stale safe and preserves reviewed corrections', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 13:00:00', 'UTC'));
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/457']);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/457',
        'source_url' => 'https://www.openstreetmap.org/node/457',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-restore-a',
        'payload' => ['name' => 'Name A'],
    ];
    $recorder->record($spot, $base);
    $target = PlaceFactObservation::query()->sole();
    $recorder->record($spot, [
        ...$base,
        'observed_at' => '2026-09-17T11:00:00+00:00',
        'ingestion_key' => 'osm-restore-b',
        'payload' => ['name' => 'Name B'],
    ]);
    $stalePreview = $recorder->previewRestore($target->id);

    $review = app(ReviewPlaceFacts::class);
    $namePreview = $review->preview($spot->id, ['name' => 'Reviewed place name']);
    $review->apply($spot->id, ['name' => 'Reviewed place name'], $namePreview['fingerprint'], 'Official evidence confirms this reviewed place name.', 'reviewer@example.test');

    $recorder->record($spot, [
        ...$base,
        'observed_at' => '2026-09-17T12:00:00+00:00',
        'ingestion_key' => 'osm-restore-c',
        'payload' => ['name' => 'Name C'],
    ]);

    expect(fn () => $recorder->restore(
        $target->id,
        $stalePreview['current']['payload_hash'],
        'operator@example.test',
        'Restore the last approved source snapshot after a bad import.',
    ))->toThrow(DomainException::class);

    $preview = $recorder->previewRestore($target->id);
    $revision = app(PlaceFacts::class)->revision();
    expect($recorder->restore(
        $target->id,
        $preview['current']['payload_hash'],
        'operator@example.test',
        'Restore the last approved source snapshot after a bad import.',
    ))->toBeTrue();

    $restored = PlaceFactObservation::query()->latest('id')->firstOrFail();
    expect($restored->payload)->toBe(['name' => 'Name A'])
        ->and($restored->record_kind)->toBe('restore')
        ->and($restored->restores_observation_id)->toBe($target->id)
        ->and($restored->actor)->toBe('operator@example.test')
        ->and($restored->reason)->toBe('Restore the last approved source snapshot after a bad import.')
        ->and(app(PlaceFacts::class)->resolve($spot->fresh())['name']['value'])->toBe('Reviewed place name')
        ->and(PlaceFactCorrection::query()->count())->toBe(1)
        ->and(app(PlaceFacts::class)->revision())->toBe($revision + 1)
        ->and($recorder->restore(
            $target->id,
            $preview['current']['payload_hash'],
            'operator@example.test',
            'Restore the last approved source snapshot after a bad import.',
        ))->toBeFalse()
        ->and(PlaceFactObservation::query()->count())->toBe(4);
});

test('the source restore command previews by default and requires the exact current hash to apply', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 13:00:00', 'UTC'));
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/458']);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/458',
        'source_url' => 'https://www.openstreetmap.org/node/458',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-command-a',
        'payload' => ['name' => 'Name A'],
    ];
    $recorder->record($spot, $base);
    $target = PlaceFactObservation::query()->sole();
    $recorder->record($spot, [
        ...$base,
        'observed_at' => '2026-09-17T11:00:00+00:00',
        'ingestion_key' => 'osm-command-b',
        'payload' => ['name' => 'Name B'],
    ]);

    $this->artisan('places:restore-observation', ['observation' => $target->id])->assertSuccessful();
    expect(PlaceFactObservation::query()->count())->toBe(2);

    $this->artisan('places:restore-observation', [
        'observation' => $target->id,
        '--apply' => true,
        '--expected-current-hash' => str_repeat('0', 64),
        '--actor' => 'operator@example.test',
        '--reason' => 'Restore the last approved source snapshot after a bad import.',
    ])->assertFailed();

    $preview = $recorder->previewRestore($target->id);
    $this->artisan('places:restore-observation', [
        'observation' => $target->id,
        '--apply' => true,
        '--expected-current-hash' => $preview['current']['payload_hash'],
        '--actor' => 'operator@example.test',
        '--reason' => 'Restore the last approved source snapshot after a bad import.',
    ])->assertSuccessful();

    expect(PlaceFactObservation::query()->count())->toBe(3)
        ->and(PlaceFactObservation::query()->latest('id')->value('payload_hash'))->toBe($target->payload_hash);
});

test('a reviewed display name survives source refresh while source names remain inspectable', function () {
    $spot = Spot::factory()->create(['name' => 'Legacy display', 'source' => 'osm', 'source_id' => 'node/789']);
    $recorder = app(RecordPlaceObservation::class);
    $recorder->record($spot, [
        'provider' => 'osm',
        'provider_record_id' => 'node/789',
        'source_url' => 'https://www.openstreetmap.org/node/789',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-name-a',
        'payload' => ['name' => 'Original source name'],
    ]);
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($spot->id, ['name' => 'Friendly reviewed name']);
    $review->apply(
        $spot->id,
        ['name' => 'Friendly reviewed name'],
        $preview['fingerprint'],
        'https://official.example.test/places/789',
        'places-reviewer@example.test',
    );
    $recorder->record($spot, [
        'provider' => 'osm',
        'provider_record_id' => 'node/789',
        'source_url' => 'https://www.openstreetmap.org/node/789',
        'observed_at' => '2026-09-17T12:00:00+00:00',
        'ingestion_key' => 'osm-name-b',
        'payload' => ['name' => 'Latest source name'],
    ]);

    $facts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($facts['name']['value'])->toBe('Friendly reviewed name')
        ->and($facts['name_kind'])->toBe('reviewed')
        ->and($facts['aliases'])->toContain('Original source name', 'Latest source name')
        ->and(PlaceFactCorrection::query()->count())->toBe(1);
});

test('a fact review rejects a stale source snapshot', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/790']);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/790',
        'source_url' => 'https://www.openstreetmap.org/node/790',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-stale-a',
        'payload' => ['name' => 'First name'],
    ];
    $recorder->record($spot, $base);
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($spot->id, ['name' => 'Reviewed name']);
    $recorder->record($spot, [...$base, 'observed_at' => '2026-09-17T11:00:00+00:00', 'ingestion_key' => 'osm-stale-b', 'payload' => ['name' => 'Changed source name']]);

    expect(fn () => $review->apply(
        $spot->id,
        ['name' => 'Reviewed name'],
        $preview['fingerprint'],
        'Official source evidence for the reviewed name.',
        'places-reviewer@example.test',
    ))->toThrow(DomainException::class)
        ->and(PlaceFactCorrection::query()->count())->toBe(0);
});

test('a fact review requires a named actor and documented evidence', function () {
    $spot = Spot::factory()->create();
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($spot->id, ['name' => 'Reviewed place name']);

    expect(fn () => $review->apply($spot->id, ['name' => 'Reviewed place name'], $preview['fingerprint'], 'Official source evidence confirms the reviewed name.', ''))
        ->toThrow(DomainException::class)
        ->and(fn () => $review->apply($spot->id, ['name' => 'Reviewed place name'], $preview['fingerprint'], 'Too short', 'reviewer@example.test'))
        ->toThrow(DomainException::class)
        ->and(PlaceFactCorrection::query()->count())->toBe(0);
});

test('a newer restrictive source conflicts with an older public access correction and fails closed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 11:00:00', 'UTC'));
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/791']);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/791',
        'source_url' => 'https://www.openstreetmap.org/node/791',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-access-a',
        'payload' => ['access' => ['raw' => 'yes']],
    ];
    $recorder->record($spot, $base);
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($spot->id, ['access' => 'public']);
    $review->apply($spot->id, ['access' => 'public'], $preview['fingerprint'], 'Official access policy confirmed public entry.', 'reviewer@example.test');
    $recorder->record($spot, [...$base, 'observed_at' => '2026-09-17T14:00:00+00:00', 'ingestion_key' => 'osm-access-b', 'payload' => ['access' => ['raw' => 'private']]]);

    $facts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($facts['access']['value'])->toBe('private')
        ->and($facts['access']['status'])->toBe('conflicting')
        ->and($facts['access']['reviewed_value'])->toBe('public')
        ->and($facts['conflicts'])->toContain('access');
});

test('a newer conditional source conflicts with an older public access correction and fails closed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 11:00:00', 'UTC'));
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/795']);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/795',
        'source_url' => 'https://www.openstreetmap.org/node/795',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-conditional-review-a',
        'payload' => ['access' => ['raw' => 'yes', 'conditional' => null]],
    ];
    $recorder->record($spot, $base);
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($spot->id, ['access' => 'public']);
    $review->apply($spot->id, ['access' => 'public'], $preview['fingerprint'], 'Official access policy confirmed unrestricted public entry.', 'reviewer@example.test');
    $recorder->record($spot, [
        ...$base,
        'observed_at' => '2026-09-17T14:00:00+00:00',
        'ingestion_key' => 'osm-conditional-review-b',
        'payload' => ['access' => ['raw' => 'yes', 'conditional' => 'no @ (22:00-06:00)']],
    ]);

    $facts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($facts['access'])->toMatchArray([
        'value' => 'unknown',
        'status' => 'conflicting',
        'conditional' => 'no @ (22:00-06:00)',
        'reviewed_value' => 'public',
    ])->and($facts['conflicts'])->toContain('access')
        ->and(Spot::query()->recommendationEligible()->whereKey($spot)->exists())->toBeFalse();
});

test('multiple active access corrections are conflicting and fail closed', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/796']);
    PlaceFactCorrection::factory()->for($spot)->create([
        'field' => 'access',
        'value' => ['value' => 'public'],
        'reviewed_at' => '2026-09-17T10:00:00+00:00',
    ]);
    PlaceFactCorrection::factory()->for($spot)->create([
        'field' => 'access',
        'value' => ['value' => 'private'],
        'reviewed_at' => '2026-09-17T11:00:00+00:00',
    ]);

    $facts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($facts['access']['status'])->toBe('conflicting')
        ->and($facts['conflicts'])->toContain('access')
        ->and(Spot::query()->recommendationEligible()->whereKey($spot)->exists())->toBeFalse();
});

test('the shared recommendation scope follows reviewed access and newer restrictive evidence', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 11:00:00', 'UTC'));
    $spot = Spot::factory()->create([
        'name' => 'Access-controlled place',
        'source' => 'osm',
        'source_id' => 'node/792',
        'tags' => null,
        'is_active' => true,
        'is_recommendable' => true,
    ]);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/792',
        'source_url' => 'https://www.openstreetmap.org/node/792',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-scope-a',
        'payload' => ['access' => ['raw' => 'private', 'conditional' => null]],
    ];
    $recorder->record($spot, $base);

    expect(Spot::query()->recommendationEligible()->whereKey($spot)->exists())->toBeFalse();

    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($spot->id, ['access' => 'public']);
    $review->apply($spot->id, ['access' => 'public'], $preview['fingerprint'], 'Official access policy confirms public entry.', 'reviewer@example.test');

    expect(Spot::query()->recommendationEligible()->whereKey($spot)->exists())->toBeTrue();

    $recorder->record($spot, [
        ...$base,
        'observed_at' => '2026-09-17T14:00:00+00:00',
        'ingestion_key' => 'osm-scope-b',
        'payload' => ['access' => ['raw' => 'members', 'conditional' => null]],
    ]);

    expect(Spot::query()->recommendationEligible()->whereKey($spot)->exists())->toBeFalse();
});

test('a current conditional access rule is not publicly recommended', function () {
    $spot = Spot::factory()->create([
        'source' => 'osm',
        'source_id' => 'node/793',
        'tags' => null,
        'is_active' => true,
        'is_recommendable' => true,
    ]);
    app(RecordPlaceObservation::class)->record($spot, [
        'provider' => 'osm',
        'provider_record_id' => 'node/793',
        'source_url' => 'https://www.openstreetmap.org/node/793',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-conditional-793', // gitleaks:allow -- deterministic test fixture, not a credential
        'payload' => ['access' => ['raw' => 'yes', 'conditional' => 'no @ (22:00-06:00)']],
    ]);

    expect(Spot::query()->recommendationEligible()->whereKey($spot)->exists())->toBeFalse();
});

test('source-backed practical facts preserve unsupported raw hours and negative details', function () {
    $spot = Spot::factory()->create([
        'name' => 'Source projection',
        'source' => 'osm',
        'source_id' => 'way/800',
        'lat' => 50.95,
        'lng' => 6.95,
        'website' => null,
        'phone' => null,
        'address' => null,
        'description' => null,
        'tags' => [],
    ]);
    app(RecordPlaceObservation::class)->record($spot, [
        'provider' => 'osm',
        'provider_record_id' => 'way/800',
        'source_url' => 'https://www.openstreetmap.org/way/800',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-practical-800', // gitleaks:allow -- deterministic test fixture, not a credential
        'payload' => [
            'location' => ['lat' => 50.95, 'lng' => 6.95, 'kind' => 'source_center', 'boundary_reference' => 'way/800'],
            'fee' => ['raw' => 'yes'],
            'hours' => ['raw' => '08:00-sunset'],
            'contact' => ['website' => 'https://official.example.test/place', 'phone' => '+49 221 800', 'address' => 'Parkweg 8, Köln'],
            'description' => 'Short permitted source description.',
            'negative_facts' => ['wheelchair' => 'no', 'lit' => 'no'],
        ],
    ]);

    $facts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($facts['location']['map_point'])->toMatchArray(['lat' => 50.95, 'lng' => 6.95, 'kind' => 'source_center', 'boundary_reference' => 'way/800'])
        ->and($facts['fee'])->toMatchArray(['value' => 'paid', 'status' => 'known', 'raw' => 'yes'])
        ->and($facts['hours'])->toMatchArray(['status' => 'unknown', 'raw' => '08:00-sunset', 'parsed' => null])
        ->and($facts['contact']['website']['value'])->toBe('https://official.example.test/place')
        ->and($facts['description']['value'])->toBe('Short permitted source description.')
        ->and($facts['negative_facts'])->toMatchArray(['wheelchair' => 'no', 'lit' => 'no']);
});

test('a successful source refresh treats omitted facts as missing instead of reviving stale values', function () {
    $spot = Spot::factory()->create([
        'name' => 'Legacy source name',
        'category' => 'park',
        'source' => 'osm',
        'source_id' => 'node/807',
        'tags' => ['fee' => 'yes', 'opening_hours' => '24/7'],
        'website' => 'https://stale.example.test',
        'phone' => '+49 221 807',
        'address' => 'Stale Straße 7',
        'description' => 'Stale source description.',
    ]);
    $recorder = app(RecordPlaceObservation::class);
    $base = [
        'provider' => 'osm',
        'provider_record_id' => 'node/807',
        'source_url' => 'https://www.openstreetmap.org/node/807',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-missing-a',
        'payload' => [
            'name' => 'Earlier source name',
            'aliases' => ['Earlier alias'],
            'fee' => ['raw' => 'yes'],
            'hours' => ['raw' => '24/7'],
            'contact' => [
                'website' => 'https://old.example.test',
                'phone' => '+49 221 111',
                'address' => 'Old Straße 1',
            ],
            'description' => 'Earlier source description.',
        ],
    ];
    $recorder->record($spot, $base);
    $recorder->record($spot, [
        ...$base,
        'observed_at' => '2026-09-17T11:00:00+00:00',
        'ingestion_key' => 'osm-missing-b',
        'payload' => [
            'name' => null,
            'aliases' => [],
        ],
    ]);

    $facts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($facts['name'])->toMatchArray(['value' => 'Park', 'status' => 'known'])
        ->and($facts['name_kind'])->toBe('descriptive')
        ->and($facts['aliases'])->toContain('Earlier source name', 'Earlier alias')
        ->and($facts['fee'])->toMatchArray(['value' => 'unknown', 'raw' => null, 'status' => 'unknown'])
        ->and($facts['hours'])->toMatchArray(['value' => null, 'raw' => null, 'parsed' => null, 'status' => 'unknown'])
        ->and($facts['contact']['website'])->toMatchArray(['value' => null, 'status' => 'unknown'])
        ->and($facts['contact']['phone'])->toMatchArray(['value' => null, 'status' => 'unknown'])
        ->and($facts['contact']['address'])->toMatchArray(['value' => null, 'status' => 'unknown'])
        ->and($facts['description'])->toMatchArray(['value' => null, 'status' => 'unknown']);
});

test('a page of places resolves facts in a fixed number of queries', function () {
    $spots = Spot::factory()->count(8)->create(['source' => 'osm']);
    $recorder = app(RecordPlaceObservation::class);
    foreach ($spots as $index => $spot) {
        $spot->update(['source_id' => "node/{$spot->id}"]);
        $recorder->record($spot, [
            'provider' => 'osm',
            'provider_record_id' => "node/{$spot->id}",
            'source_url' => "https://www.openstreetmap.org/node/{$spot->id}",
            'observed_at' => '2026-09-17T10:00:00+00:00',
            'ingestion_key' => "osm-batch-{$spot->id}",
            'payload' => ['name' => "Source place {$index}"],
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $resolved = app(PlaceFacts::class)->resolveMany($spots);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($resolved)->toHaveCount(8)
        ->and($resolved->pluck('name.value')->all())->toBe([
            'Source place 0',
            'Source place 1',
            'Source place 2',
            'Source place 3',
            'Source place 4',
            'Source place 5',
            'Source place 6',
            'Source place 7',
        ])
        ->and($queries)->toHaveCount(5);
});

test('a reviewed entrance is a routing point without replacing the source map point', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'way/801', 'lat' => 50.95, 'lng' => 6.95]);
    app(RecordPlaceObservation::class)->record($spot, [
        'provider' => 'osm',
        'provider_record_id' => 'way/801',
        'source_url' => 'https://www.openstreetmap.org/way/801',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-location-801',
        'payload' => ['location' => [
            'lat' => 50.95,
            'lng' => 6.95,
            'kind' => 'source_center',
            'boundary_reference' => 'way/801',
            'entrance_point' => ['lat' => 50.9502, 'lng' => 6.9503, 'status' => 'candidate'],
        ]],
    ]);
    $candidateFacts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($candidateFacts['location']['entrance_point'])->toMatchArray([
        'lat' => 50.9502,
        'lng' => 6.9503,
        'status' => 'candidate',
        'source_url' => 'https://www.openstreetmap.org/way/801',
    ]);

    $review = app(ReviewPlaceFacts::class);
    $changes = ['entrance_point' => ['lat' => 50.9504, 'lng' => 6.9505]];
    $preview = $review->preview($spot->id, $changes);
    $review->apply($spot->id, $changes, $preview['fingerprint'], 'https://official.example.test/places/801/entrance', 'reviewer@example.test');

    $facts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($facts['location']['map_point'])->toMatchArray([
        'lat' => 50.95,
        'lng' => 6.95,
        'kind' => 'source_center',
        'boundary_reference' => 'way/801',
    ])->and($facts['location']['entrance_point'])->toMatchArray([
        'lat' => 50.9504,
        'lng' => 6.9505,
        'status' => 'verified',
        'source_url' => 'https://official.example.test/places/801/entrance',
    ])->and($spot->fresh()->only(['lat', 'lng']))->toBe(['lat' => 50.95, 'lng' => 6.95]);
});

test('reviewed practical facts override source values with evidence metadata', function () {
    $spot = Spot::factory()->create([
        'source' => 'osm',
        'source_id' => 'node/802',
        'tags' => ['fee' => 'no', 'opening_hours' => '24/7'],
        'website' => 'https://old.example.test',
        'description' => 'Old source description.',
    ]);
    $review = app(ReviewPlaceFacts::class);
    $changes = [
        'fee' => ['value' => 'paid', 'amount' => 5.5, 'currency' => 'EUR'],
        'hours' => ['raw' => 'Mo-Fr 09:00-18:00'],
        'contact' => ['website' => 'https://official.example.test/place-802', 'phone' => '+49 221 802', 'address' => 'Domstraße 8, Köln'],
        'description' => 'Reviewed short factual description.',
    ];
    $preview = $review->preview($spot->id, $changes);
    $review->apply($spot->id, $changes, $preview['fingerprint'], 'https://official.example.test/place-802', 'reviewer@example.test');

    $facts = app(PlaceFacts::class)->resolve($spot->fresh());

    expect($facts['fee'])->toMatchArray([
        'value' => 'paid',
        'status' => 'known',
        'amount' => 5.5,
        'currency' => 'EUR',
        'source_url' => 'https://official.example.test/place-802',
    ])->and($facts['hours'])->toMatchArray([
        'status' => 'known',
        'raw' => 'Mo-Fr 09:00-18:00',
        'source_url' => 'https://official.example.test/place-802',
    ])->and($facts['contact']['website']['value'])->toBe('https://official.example.test/place-802')
        ->and($facts['contact']['phone']['value'])->toBe('+49 221 802')
        ->and($facts['description']['value'])->toBe('Reviewed short factual description.')
        ->and($facts['description']['reviewed_at'])->not->toBeNull();
});

test('revoking a reviewed fact restores the source value and keeps an audit trail', function () {
    $spot = Spot::factory()->create(['name' => 'Source display name', 'source' => 'osm', 'source_id' => 'node/803']);
    $review = app(ReviewPlaceFacts::class);
    $apply = $review->preview($spot->id, ['name' => 'Reviewed display name']);
    $review->apply($spot->id, ['name' => 'Reviewed display name'], $apply['fingerprint'], 'Official name evidence for this destination.', 'reviewer@example.test');
    $revisionAfterApply = app(PlaceFacts::class)->revision();

    $revoke = $review->preview($spot->id, ['name' => null]);
    $review->apply($spot->id, ['name' => null], $revoke['fingerprint'], 'Correction revoked after the official source was rechecked.', 'reviewer@example.test');

    $corrections = PlaceFactCorrection::query()->orderBy('id')->get();
    expect(app(PlaceFacts::class)->resolve($spot->fresh())['name']['value'])->toBe('Source display name')
        ->and($corrections)->toHaveCount(2)
        ->and($corrections[0]->revoked_at)->not->toBeNull()
        ->and($corrections[1]->value)->toBeNull()
        ->and($corrections[1]->revoked_at)->not->toBeNull()
        ->and($corrections[1]->supersedes_id)->toBe($corrections[0]->id)
        ->and(app(PlaceFacts::class)->revision())->toBe($revisionAfterApply + 1);
});

test('the fact review command previews by default and applies only an exact reviewed fingerprint', function () {
    $spot = Spot::factory()->create(['name' => 'Source command name']);
    $path = storage_path("framework/testing/place-facts-{$spot->id}.json");
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, json_encode(['name' => 'Reviewed command name'], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('places:review-facts', ['spot' => $spot->id, '--changes' => $path])->assertSuccessful();
        expect(PlaceFactCorrection::query()->count())->toBe(0);

        $this->artisan('places:review-facts', [
            'spot' => $spot->id,
            '--changes' => $path,
            '--apply' => true,
            '--fingerprint' => 'wrong',
            '--evidence' => 'Official evidence confirms the reviewed command name.',
            '--actor' => 'reviewer@example.test',
        ])->assertFailed();

        $preview = app(ReviewPlaceFacts::class)->preview($spot->id, ['name' => 'Reviewed command name']);
        $this->artisan('places:review-facts', [
            'spot' => $spot->id,
            '--changes' => $path,
            '--apply' => true,
            '--fingerprint' => $preview['fingerprint'],
            '--evidence' => 'Official evidence confirms the reviewed command name.',
            '--actor' => 'reviewer@example.test',
        ])->assertSuccessful();

        expect(app(PlaceFacts::class)->resolve($spot->fresh())['name']['value'])->toBe('Reviewed command name');
    } finally {
        @unlink($path);
    }
});

test('identity reconciliation preserves non-conflicting fact history on the canonical place', function () {
    $alias = Spot::factory()->create([
        'name' => 'Same physical place',
        'category' => 'playground',
        'source' => null,
        'source_id' => null,
        'lat' => 50.95,
        'lng' => 6.95,
    ]);
    $canonical = Spot::factory()->create([
        'name' => 'Same physical place',
        'category' => 'playground',
        'source' => 'osm',
        'source_id' => 'node/804',
        'lat' => 50.95,
        'lng' => 6.95,
    ]);
    app(RecordPlaceObservation::class)->record($alias, [
        'provider' => 'legacy_review',
        'provider_record_id' => "spot/{$alias->id}",
        'source_url' => 'https://official.example.test/place-804',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'legacy-place-804',
        'payload' => ['name' => 'Original reviewed source name'],
    ]);
    $review = app(ReviewPlaceFacts::class);
    $factPreview = $review->preview($alias->id, ['name' => 'Friendly canonical name']);
    $review->apply($alias->id, ['name' => 'Friendly canonical name'], $factPreview['fingerprint'], 'Official evidence confirms this destination name.', 'reviewer@example.test');
    $before = app(PlaceFacts::class)->revision();

    $identity = app(ReconcilePlace::class);
    $identityPreview = $identity->preview($alias->id, $canonical->id);
    $identity->apply($alias->id, $canonical->id, $identityPreview['fingerprint'], 'Reviewed source evidence confirms these records are one physical place.');

    $facts = app(PlaceFacts::class)->resolve($canonical->fresh());
    $aliasFacts = app(PlaceFacts::class)->resolve($alias->fresh());
    expect(PlaceFactObservation::query()->sole()->spot_id)->toBe($canonical->id)
        ->and(PlaceFactCorrection::query()->sole()->spot_id)->toBe($canonical->id)
        ->and($facts['name']['value'])->toBe('Friendly canonical name')
        ->and($facts['aliases'])->toContain('Original reviewed source name')
        ->and($aliasFacts['name']['value'])->toBe('Friendly canonical name')
        ->and(app(PlaceFacts::class)->revision())->toBe($before + 1);
});

test('identity reconciliation refuses overlapping active fact corrections', function () {
    $alias = Spot::factory()->create([
        'name' => 'Shared place name',
        'category' => 'park',
        'source' => null,
        'source_id' => null,
        'lat' => 50.95,
        'lng' => 6.95,
    ]);
    $canonical = Spot::factory()->create([
        'name' => 'Shared place name',
        'category' => 'park',
        'source' => 'osm',
        'source_id' => 'way/808',
        'lat' => 50.95,
        'lng' => 6.95,
    ]);
    $review = app(ReviewPlaceFacts::class);
    foreach ([[$alias, 'Reviewed alias name'], [$canonical, 'Reviewed canonical name']] as [$spot, $name]) {
        $factPreview = $review->preview($spot->id, ['name' => $name]);
        $review->apply($spot->id, ['name' => $name], $factPreview['fingerprint'], 'Official evidence confirms this reviewed name.', 'reviewer@example.test');
    }

    $identity = app(ReconcilePlace::class);
    $preview = $identity->preview($alias->id, $canonical->id);

    expect(fn () => $identity->apply($alias->id, $canonical->id, $preview['fingerprint'], 'Reviewed evidence suggests these records share an identity.'))
        ->toThrow(DomainException::class)
        ->and($alias->fresh()->canonical_spot_id)->toBeNull()
        ->and(PlaceFactCorrection::query()->where('spot_id', $alias->id)->count())->toBe(1)
        ->and(PlaceFactCorrection::query()->where('spot_id', $canonical->id)->count())->toBe(1);
});

test('fact history prevents direct deletion and destructive category pruning', function () {
    $spot = Spot::factory()->create(['category' => 'park', 'source' => 'osm', 'source_id' => 'way/805']);
    app(RecordPlaceObservation::class)->record($spot, [
        'provider' => 'osm',
        'provider_record_id' => 'way/805',
        'source_url' => 'https://www.openstreetmap.org/way/805',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-protected-805', // gitleaks:allow -- deterministic test fixture, not a credential
        'payload' => ['name' => 'Protected history'],
    ]);

    expect(fn () => $spot->delete())->toThrow(DomainException::class);
    $this->artisan('spots:prune', ['--category' => ['park'], '--force' => true])->assertFailed();
    expect($spot->fresh())->not->toBeNull()
        ->and(PlaceFactObservation::query()->count())->toBe(1);
});

test('fact history prevents catalogue snapshot replacement', function () {
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'node/806']);
    app(RecordPlaceObservation::class)->record($spot, [
        'provider' => 'osm',
        'provider_record_id' => 'node/806',
        'source_url' => 'https://www.openstreetmap.org/node/806',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-protected-806', // gitleaks:allow -- deterministic test fixture, not a credential
        'payload' => ['name' => 'Protected snapshot history'],
    ]);
    $path = storage_path("framework/testing/spots-{$spot->id}.json.gz");
    $relative = str($path)->after(base_path().DIRECTORY_SEPARATOR)->toString();
    $this->artisan('spots:snapshot', ['--path' => $relative])->assertSuccessful();

    try {
        $this->artisan('spots:load', ['--path' => $relative, '--force' => true])->assertFailed();
        expect($spot->fresh())->not->toBeNull()
            ->and(PlaceFactObservation::query()->count())->toBe(1);
    } finally {
        @unlink($path);
    }
});
