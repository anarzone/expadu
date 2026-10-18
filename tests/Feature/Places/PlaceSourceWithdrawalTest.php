<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Models\PlaceFactObservation;
use App\Models\Spot;
use App\Models\User;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\ReconcilePlace;
use App\Places\RecordPlaceObservation;
use App\Places\ReviewPlaceFacts;
use App\Places\WithdrawPlaceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-29T12:00:00Z'));
    $this->actingAs(User::factory()->onboarded()->create());
});

function withdrawalSpot(array $attributes = []): Spot
{
    return Spot::factory()->create([
        'name' => 'Legacy riverside park', 'category' => 'park',
        'source' => 'osm', 'source_id' => 'node/84001',
        'lat' => 50.95, 'lng' => 6.95, 'is_active' => true, 'is_recommendable' => true,
        'tags' => ['access' => 'private', 'fee' => 'no', 'fee:conditional' => 'yes @ (Mo-Fr)', 'sport' => 'soccer', 'surface' => 'grass'],
        ...$attributes,
    ]);
}

function withdrawalObservation(Spot $spot, array $changes = []): PlaceFactObservation
{
    app(RecordPlaceObservation::class)->record($spot, [
        'provider' => 'osm', 'provider_record_id' => $spot->source_id,
        'source_url' => 'https://www.openstreetmap.org/'.$spot->source_id,
        'observed_at' => '2026-09-29T10:00:00Z', 'ingestion_key' => 'withdrawal-source-fixture',
        'payload' => ['name' => 'Imported park', 'aliases' => ['Imported alias'], 'access' => ['raw' => 'yes'], 'fee' => ['raw' => 'no', 'conditional' => null], 'practical' => ['sport' => 'tennis']],
        ...$changes,
    ]);

    return PlaceFactObservation::where('spot_id', $spot->id)->latest('id')->firstOrFail();
}

function withdrawSource(PlaceFactObservation $source): bool
{
    $service = app(WithdrawPlaceObservation::class);

    return $service->apply($source->id, $service->preview($source->id)['fingerprint'], 'test-operator', 'Recover the first source observation without erasing its audit history.');
}

it('withdraws a first source without losing legacy facts or keeping imported aliases', function () {
    $spot = withdrawalSpot();
    $before = app(PlaceFacts::class)->resolve($spot->fresh());
    $source = withdrawalObservation($spot);
    $original = $source->getRawOriginal();
    expect(app(PlaceFacts::class)->resolve($spot->fresh())['name']['value'])->toBe('Imported park')
        ->and(app(DestinationGrouping::class)->general(Spot::query())->whereKey($spot->id)->exists())->toBeTrue();
    expect(withdrawSource($source))->toBeTrue();
    $facts = app(PlaceFacts::class)->resolve($spot->fresh());
    expect($facts['name']['value'])->toBe('Legacy riverside park')
        ->and($facts['aliases'])->toBe([])
        ->and($facts['access']['value'])->toBe('private')
        ->and($facts['fee']['value'])->toBe('unknown')
        ->and($facts['fee']['conditional'])->toBe('yes @ (Mo-Fr)')
        ->and($facts['practical']['sport']['value'])->toBe('soccer')
        ->and($facts['practical']['surface']['value'])->toBe('grass')
        ->and($facts['revision'])->toBeGreaterThan($before['revision'])
        ->and($source->fresh()->getRawOriginal())->toBe($original)
        ->and(PlaceFactObservation::count())->toBe(2)
        ->and(app(DestinationGrouping::class)->general(Spot::query())->whereKey($spot->id)->exists())->toBeFalse()
        ->and(app(CandidateRepository::class)->byIds(['spot:'.$spot->id], CarbonImmutable::today()))->toBe([]);
    $this->getJson('/api/places/'.$spot->id)->assertSuccessful()
        ->assertJsonPath('data.name', 'Legacy riverside park')
        ->assertJsonPath('data.price_text', null)
        ->assertJsonPath('data.place_facts.access.value', 'private');
});

it('does not reveal a withdrawn restriction when the legacy access is unknown', function () {
    $spot = withdrawalSpot(['tags' => []]);
    $source = withdrawalObservation($spot, ['payload' => ['name' => 'Restricted imported park', 'access' => ['raw' => 'private']]]);
    expect(app(DestinationGrouping::class)->general(Spot::query())->whereKey($spot->id)->exists())->toBeFalse();
    withdrawSource($source);
    expect(app(PlaceFacts::class)->resolve($spot->fresh())['access']['value'])->toBe('unknown')
        ->and(app(DestinationGrouping::class)->general(Spot::query())->whereKey($spot->id)->exists())->toBeTrue();
});

it('preserves other providers and reviewed names while withdrawing one source', function () {
    $spot = withdrawalSpot(['tags' => []]);
    withdrawalObservation($spot, ['provider' => 'test_provider', 'provider_record_id' => 'other-84001', 'ingestion_key' => 'other-source', 'payload' => ['name' => 'Other name', 'access' => ['raw' => 'private']]]);
    $source = withdrawalObservation($spot);
    $reviews = app(ReviewPlaceFacts::class);
    $preview = $reviews->preview($spot->id, ['name' => 'Reviewed park']);
    $reviews->apply($spot->id, ['name' => 'Reviewed park'], $preview['fingerprint'], 'https://example.test/verified-place-name', 'reviewer');
    withdrawSource($source);
    $facts = app(PlaceFacts::class)->resolve($spot->fresh());
    expect($facts['name']['value'])->toBe('Reviewed park')
        ->and($facts['aliases'])->toBe(['Other name'])
        ->and($facts['access']['value'])->toBe('private')
        ->and($spot->factCorrections()->count())->toBe(1)
        ->and(PlaceFactObservation::where('provider', 'test_provider')->count())->toBe(1);
});

it('accepts a later genuine source arrival after withdrawal even with unchanged facts or an older timestamp', function (string $observedAt) {
    $spot = withdrawalSpot(['tags' => []]);
    $source = withdrawalObservation($spot);
    withdrawSource($source);
    $revision = app(PlaceFacts::class)->revision();
    withdrawalObservation($spot, ['observed_at' => $observedAt, 'ingestion_key' => 'new-real-source']);
    $facts = app(PlaceFacts::class)->resolve($spot->fresh());
    expect($facts['name']['value'])->toBe('Imported park')
        ->and($facts['practical']['sport']['value'])->toBe('tennis')
        ->and($facts['revision'])->toBe($revision + 1)
        ->and(PlaceFactObservation::count())->toBe(3)
        ->and(app(DestinationGrouping::class)->general(Spot::query())->whereKey($spot->id)->exists())->toBeTrue();
})->with(['2026-09-29T12:00:00Z', '2026-09-29T09:00:00Z']);

it('replays only the exact withdrawal without adding audit or revision churn', function () {
    $spot = withdrawalSpot(['tags' => []]);
    $source = withdrawalObservation($spot);
    $service = app(WithdrawPlaceObservation::class);
    $preview = $service->preview($source->id);
    $reason = 'Recover the first source observation without erasing its audit history.';
    expect($service->apply($source->id, $preview['fingerprint'], 'test-operator', $reason))->toBeTrue();
    $revision = app(PlaceFacts::class)->revision();
    expect($service->apply($source->id, $preview['fingerprint'], 'test-operator', $reason))->toBeFalse()
        ->and(app(PlaceFacts::class)->revision())->toBe($revision)
        ->and(PlaceFactObservation::count())->toBe(2)
        ->and(fn () => $service->apply($source->id, str_repeat('0', 64), 'test-operator', $reason))->toThrow(DomainException::class)
        ->and(fn () => $service->apply($source->id, $preview['fingerprint'], 'another-operator', $reason))->toThrow(DomainException::class);
});

it('refuses a stale withdrawal preview and a stream with earlier history', function () {
    $spot = withdrawalSpot();
    $source = withdrawalObservation($spot);
    $service = app(WithdrawPlaceObservation::class);
    $preview = $service->preview($source->id);
    withdrawalObservation($spot, ['observed_at' => '2026-09-29T11:00:00Z', 'ingestion_key' => 'changed-after-preview', 'payload' => ['name' => 'Later reviewed source']]);
    expect(fn () => $service->apply($source->id, $preview['fingerprint'], 'test-operator', 'Preserve the later source update after a stale preview.'))->toThrow(DomainException::class)
        ->and(PlaceFactObservation::count())->toBe(2)
        ->and(app(PlaceFacts::class)->resolve($spot->fresh())['name']['value'])->toBe('Later reviewed source');
});

it('rejects invalid withdrawal acknowledgements without changing history', function (string $field, string $value) {
    $source = withdrawalObservation(withdrawalSpot());
    $service = app(WithdrawPlaceObservation::class);
    $args = ['fingerprint' => $service->preview($source->id)['fingerprint'], 'actor' => 'test-operator', 'reason' => 'Recover the initial source while retaining the immutable audit history.'];
    $args[$field] = $value;
    expect(fn () => $service->apply($source->id, ...$args))->toThrow(DomainException::class)
        ->and(PlaceFactObservation::count())->toBe(1);
})->with([
    ['fingerprint', 'invalid'], ['fingerprint', str_repeat('0', 64)],
    ['actor', ' '], ['actor', str_repeat('x', 192)], ['reason', 'Too short'],
]);

it('refuses a withdrawal after reviewed facts or canonical identity change', function (string $change) {
    $spot = withdrawalSpot(['source' => null, 'source_id' => null]);
    $source = withdrawalObservation($spot, ['provider_record_id' => 'node/84001', 'source_url' => 'https://www.openstreetmap.org/node/84001']);
    $service = app(WithdrawPlaceObservation::class);
    $preview = $service->preview($source->id);
    if ($change === 'review') {
        $review = app(ReviewPlaceFacts::class);
        $review->apply($spot->id, ['name' => 'Reviewed park'], $review->preview($spot->id, ['name' => 'Reviewed park'])['fingerprint'], 'https://example.test/reviewed-place-name', 'reviewer');
    } else {
        $canonical = withdrawalSpot();
        $reconcile = app(ReconcilePlace::class);
        $reconcile->apply($spot->id, $canonical->id, $reconcile->preview($spot->id, $canonical->id)['fingerprint'], 'The same named park and exact coordinates identify the canonical place.');
    }
    expect(fn () => $service->apply($source->id, $preview['fingerprint'], 'test-operator', 'Refuse to replace a newer canonical or reviewed place state.'))->toThrow(DomainException::class)
        ->and(PlaceFactObservation::count())->toBe(1);
})->with(['review', 'canonical']);

it('never restores a withdrawal marker or the cancelled source as factual evidence', function () {
    $source = withdrawalObservation(withdrawalSpot());
    withdrawSource($source);
    $marker = PlaceFactObservation::where('record_kind', 'withdrawal')->sole();
    foreach ([$source, $marker] as $target) {
        expect(fn () => app(RecordPlaceObservation::class)->previewRestore($target->id))->toThrow(DomainException::class)
            ->and(fn () => app(RecordPlaceObservation::class)->restore($target->id, $marker->payload_hash, 'test-operator', 'Do not turn withdrawal audit metadata into publishable place facts.'))->toThrow(DomainException::class);
    }
    expect(PlaceFactObservation::count())->toBe(2);
});

it('does not allow ordinary source ingestion to create withdrawal metadata', function () {
    $spot = withdrawalSpot();
    expect(fn () => withdrawalObservation($spot, ['payload' => ['withdraws_observation_id' => 1, 'schema_version' => 1]]))->toThrow(DomainException::class)
        ->and(PlaceFactObservation::count())->toBe(0);
});

it('keeps another observation effective after reconciling a withdrawn overlapping source stream', function () {
    $alias = withdrawalSpot(['source' => null, 'source_id' => null, 'tags' => []]);
    $source = withdrawalObservation($alias, ['provider_record_id' => 'node/84001', 'source_url' => 'https://www.openstreetmap.org/node/84001']);
    withdrawSource($source);
    $canonical = withdrawalSpot(['tags' => []]);
    withdrawalObservation($canonical, ['ingestion_key' => 'canonical-evidence', 'observed_at' => '2026-09-29T09:00:00Z', 'payload' => ['name' => 'Preserved canonical source', 'access' => ['raw' => 'private']]]);
    $reconcile = app(ReconcilePlace::class);
    $reconcile->apply($alias->id, $canonical->id, $reconcile->preview($alias->id, $canonical->id)['fingerprint'], 'The reviewed coordinates and names establish one canonical physical place.');
    $facts = app(PlaceFacts::class)->resolve($alias->fresh());
    expect($facts['name']['value'])->toBe('Preserved canonical source')
        ->and($facts['access']['value'])->toBe('private')
        ->and($facts['aliases'])->toBe([])
        ->and(PlaceFactObservation::where('spot_id', $canonical->id)->count())->toBe(3)
        ->and(app(DestinationGrouping::class)->general(Spot::query())->whereKey($canonical->id)->exists())->toBeFalse();
});

it('invalidates an old activity review when its supporting source is withdrawn', function () {
    $spot = withdrawalSpot(['category' => 'pitch', 'name' => 'Bolzplatz', 'is_recommendable' => false, 'tags' => ['sport' => 'soccer']]);
    $source = withdrawalObservation($spot, ['payload' => ['name' => null, 'access' => ['raw' => 'yes'], 'practical' => ['sport' => 'soccer']]]);
    $review = app(ReviewPlaceFacts::class);
    $changes = ['activity_discovery' => true];
    $review->apply($spot->id, $changes, $review->preview($spot->id, $changes)['fingerprint'], 'https://example.test/reviewed-football-access', 'reviewer');
    expect(app(DestinationGrouping::class)->eligible(Spot::query(), true)->whereKey($spot->id)->exists())->toBeTrue();
    withdrawSource($source);
    expect(app(DestinationGrouping::class)->eligible(Spot::query(), true)->whereKey($spot->id)->exists())->toBeFalse()
        ->and($spot->factCorrections()->count())->toBe(1);
});

it('does not let withdrawn free or sport hints escape Composer hard filters', function () {
    $spot = withdrawalSpot(['tags' => [], 'price_range' => null]);
    $source = withdrawalObservation($spot);
    withdrawSource($source);
    $start = CarbonImmutable::now();
    foreach ([['budget' => 'free'], ['activities' => ['tennis']]] as $filter) {
        $constraints = new Constraints(...[
            'windowStart' => $start,
            'windowEnd' => $start->addHours(4),
            'categories' => ['park'],
            ...$filter,
        ]);
        expect(app(CandidateRepository::class)->candidatesFor($constraints, 50.95, 6.95))->toBe([]);
    }
});

it('rejects a cancelled ingestion key so an importer cannot reintroduce raw fallback facts', function () {
    $spot = withdrawalSpot();
    $source = withdrawalObservation($spot);
    withdrawSource($source);
    $before = app(PlaceFacts::class)->resolve($spot->fresh());
    expect(fn () => DB::transaction(function () use ($spot) {
        $spot->update(['name' => 'Imported park', 'tags' => ['access' => 'yes', 'fee' => 'no', 'sport' => 'tennis']]);
        withdrawalObservation($spot);
    }))->toThrow(DomainException::class, 'withdrawn ingestion key');
    expect(app(PlaceFacts::class)->resolve($spot->fresh()))->toBe($before)
        ->and(PlaceFactObservation::count())->toBe(2);
});

it('preserves Composer price range fallback and conditional fee uncertainty after withdrawal', function (array $tags, string $cost) {
    $spot = withdrawalSpot(['tags' => $tags, 'price_range' => '€']);
    withdrawSource(withdrawalObservation($spot));
    $candidates = app(CandidateRepository::class)->byIds(['spot:'.$spot->id], CarbonImmutable::now());
    expect($candidates)->toHaveCount(1)->and($candidates[0]->costTier)->toBe($cost);
})->with([
    [[], 'low'],
    [['fee' => 'no', 'fee:conditional' => 'yes @ (Mo-Fr)'], 'unknown'],
]);
