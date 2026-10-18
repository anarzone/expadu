<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use App\Places\ReviewPlaceFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

function activityWindow(array $extra = []): Constraints
{
    return Constraints::fromArray([
        'window_start' => '2026-09-28T14:00:00+02:00',
        'window_end' => '2026-09-28T18:00:00+02:00',
        'categories' => ['pitch'], 'budget' => 'free',
        'activities' => ['soccer'], 'radius_km' => 2,
        ...$extra,
    ]);
}

function activityPitch(array $extra = []): Spot
{
    return Spot::factory()->create([
        'category' => 'pitch', 'lat' => 50.95, 'lng' => 6.95,
        'tags' => ['sport' => 'soccer', 'fee' => 'no', 'access' => 'yes'],
        ...$extra,
    ]);
}

test('football retrieval applies sport access booking and radius before caps', function () {
    $valid = activityPitch(['name' => 'Free football', 'lat' => 50.952]);
    $invalid = [
        activityPitch(['name' => 'Basketball pitch', 'tags' => ['sport' => 'basketball', 'fee' => 'no', 'access' => 'yes']]),
        activityPitch(['name' => 'Unknown sport', 'tags' => ['fee' => 'no', 'access' => 'yes']]),
        activityPitch(['name' => 'Unknown access', 'tags' => ['sport' => 'soccer', 'fee' => 'no']]),
        activityPitch(['name' => 'Book ahead', 'tags' => ['sport' => 'soccer', 'fee' => 'no', 'access' => 'yes', 'reservation' => 'required']]),
        activityPitch(['name' => 'Conditional access', 'tags' => ['sport' => 'soccer', 'fee' => 'no', 'access' => 'yes', 'access:conditional' => 'no @ (Mo)']]),
        activityPitch(['name' => 'Conditionally booked', 'tags' => ['sport' => 'soccer', 'fee' => 'no', 'access' => 'yes', 'reservation' => 'no', 'reservation:conditional' => 'yes @ (Mo-Fr)']]),
        activityPitch(['name' => 'Too far', 'lat' => 51.02]),
    ];
    $candidates = app(CandidateRepository::class)->candidatesFor(activityWindow(), 50.95, 6.95);
    expect(array_column($candidates, 'id'))->toBe(["spot:{$valid->id}"]);
});

test('nearer paid candidates cannot starve a farther verified free candidate', function () {
    foreach (range(1, 15) as $i) {
        activityPitch(['name' => 'Paid court '.$i, 'tags' => ['sport' => 'soccer', 'fee' => 'yes', 'access' => 'yes']]);
    }
    $free = activityPitch(['name' => 'Free farther pitch', 'lat' => 50.953]);
    expect(array_column(app(CandidateRepository::class)->candidatesFor(activityWindow(), 50.95, 6.95), 'id'))
        ->toBe(["spot:{$free->id}"]);
});

test('unnamed facilities require explicit auditable qualification and remain out of general discovery', function () {
    $qualified = activityPitch(['name' => 'Bolzplatz', 'is_recommendable' => false]);
    $unreviewed = activityPitch(['name' => 'Bolzplatz', 'is_recommendable' => false]);
    $review = app(ReviewPlaceFacts::class);
    $changes = ['activity_discovery' => true];
    $preview = $review->preview($qualified->id, $changes);
    $review->apply($qualified->id, $changes, $preview['fingerprint'], 'Source geometry and soccer capability checked for this public facility.', 'readiness-test');
    $repository = app(CandidateRepository::class);
    expect(array_column($repository->candidatesFor(activityWindow(), 50.95, 6.95), 'id'))->toBe(["spot:{$qualified->id}"])
        ->and(array_column($repository->candidatesFor(activityWindow(['activities' => [], 'categories' => []]), 50.95, 6.95), 'id'))->toBe([])
        ->and(array_column($repository->byIds(["spot:{$qualified->id}"], CarbonImmutable::now()), 'id'))->toBe(["spot:{$qualified->id}"])
        ->and($qualified->fresh()->is_recommendable)->toBeFalse();
    $changes = ['activity_discovery' => false];
    $preview = $review->preview($qualified->id, $changes);
    $review->apply($qualified->id, $changes, $preview['fingerprint'], 'Qualification withdrawn after the facility review was reconsidered.', 'readiness-test');
    expect($repository->candidatesFor(activityWindow(), 50.95, 6.95))->toBe([])
        ->and($repository->byIds(["spot:{$qualified->id}"], CarbonImmutable::now()))->toBe([]);
});

test('activity constraints survive serialization and category adjustments', function () {
    $constraints = activityWindow();
    expect($constraints->toArray()['activities'])->toBe(['soccer'])
        ->and($constraints->toArray()['radius_km'])->toBe(2.0)
        ->and($constraints->withCategories(['park'])->toArray()['activities'])->toBe(['soccer'])
        ->and(Constraints::fromArray($constraints->toArray())->toArray())->toBe($constraints->toArray());
});

test('an activity alone finds a reviewed grouped facility and preserves its destination id', function () {
    $parent = Spot::factory()->create(['name' => 'Park destination', 'category' => 'park', 'lat' => 50.95, 'lng' => 6.95]);
    $child = activityPitch(['name' => 'Football in park', 'parent_spot_id' => $parent->id]);
    $grouping = app(DestinationGrouping::class);
    $preview = $grouping->preview($child->id, $parent->id);
    $grouping->review($child->id, $parent->id, 'Reviewed direct football facility within this park boundary.', $preview['fingerprint']);
    $candidates = app(CandidateRepository::class)->candidatesFor(activityWindow(['categories' => []]), 50.95, 6.95);
    expect(array_column($candidates, 'id'))->toBe(["spot:{$child->id}"])
        ->and($candidates[0]->destinationGroupId)->toBe("spot:{$parent->id}");
});

test('a facility qualification becomes stale when its source snapshot changes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Europe/Berlin'));
    $place = activityPitch(['is_recommendable' => false]);
    $review = app(ReviewPlaceFacts::class);
    $changes = ['activity_discovery' => true];
    $preview = $review->preview($place->id, $changes);
    $review->apply($place->id, $changes, $preview['fingerprint'], 'Reviewed current public soccer source and exact geometry.', 'readiness-test');
    expect(app(CandidateRepository::class)->candidatesFor(activityWindow(), 50.95, 6.95))->toHaveCount(1);
    $this->travel(1)->seconds();
    $place->update(['name' => 'Source changed name']);
    expect(app(CandidateRepository::class)->candidatesFor(activityWindow(), 50.95, 6.95))->toBe([]);
});

test('unchanged refresh and rating updates preserve a facility qualification', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Europe/Berlin'));
    $place = activityPitch(['is_recommendable' => false]);
    $review = app(ReviewPlaceFacts::class);
    $changes = ['activity_discovery' => true];
    $preview = $review->preview($place->id, $changes);
    $review->apply($place->id, $changes, $preview['fingerprint'], 'Reviewed public soccer source facts and exact current geometry.', 'readiness-test');
    $this->travel(10)->seconds();
    $place->update(['last_seen_at' => now(), 'rating' => 4.5, 'photo_url' => 'https://example.test/pending.jpg']);
    expect(app(CandidateRepository::class)->candidatesFor(activityWindow(), 50.95, 6.95))->toHaveCount(1);
});

test('discovery considers current source facts and reviewed points before narrowing the search', function () {
    $place = activityPitch(['source' => 'osm', 'source_id' => 'way/901', 'lat' => 51.05, 'tags' => ['sport' => 'basketball', 'fee' => 'yes', 'access' => 'yes']]);
    app(RecordPlaceObservation::class)->record($place, [
        'provider' => $place->source, 'provider_record_id' => $place->source_id,
        'source_url' => 'https://example.test/soccer', 'observed_at' => now()->subDay()->toIso8601String(),
        'ingestion_key' => 'soccer-current',
        'payload' => ['practical' => ['sport' => 'soccer'], 'fee' => ['raw' => 'yes'], 'access' => ['raw' => 'yes'],
            'location' => ['lat' => 51.05, 'lng' => 6.95, 'kind' => 'source_center']],
    ]);
    $review = app(ReviewPlaceFacts::class);
    $changes = ['fee' => ['value' => 'free'], 'entrance_point' => ['lat' => 50.95, 'lng' => 6.95]];
    $preview = $review->preview($place->id, $changes);
    $review->apply($place->id, $changes, $preview['fingerprint'], 'https://example.test/reviewed-public-football', 'readiness-test');
    expect(array_column(app(CandidateRepository::class)->candidatesFor(activityWindow(), 50.95, 6.95), 'id'))->toBe(["spot:{$place->id}"]);
});

test('nearest discovery does not resolve the whole city once the eligible category pool is full', function () {
    Spot::factory()->count(180)->create(['category' => 'cafe', 'lat' => 51.05, 'lng' => 6.95]);
    $near = Spot::factory()->count(12)->create(['category' => 'cafe', 'lat' => 50.95, 'lng' => 6.95]);
    $facts = new class extends PlaceFacts
    {
        public int $resolved = 0;

        public function resolveMany(Collection $spots): Collection
        {
            $this->resolved += $spots->count();

            return parent::resolveMany($spots);
        }
    };
    app()->instance(PlaceFacts::class, $facts);
    $result = app(CandidateRepository::class)->candidatesFor(activityWindow(['categories' => ['cafe'], 'budget' => null, 'activities' => [], 'radius_km' => null]), 50.95, 6.95);
    expect(array_column($result, 'id'))->toBe($near->map(fn ($p) => "spot:{$p->id}")->all())
        ->and($facts->resolved)->toBeLessThan(100);
});

test('explicit facility categories discover reviewed descriptive places without inventing a sport or price', function (string $category) {
    $place = Spot::factory()->create(['name' => 'Descriptive facility', 'category' => $category,
        'source' => 'osm', 'source_id' => 'way/98765432', 'is_recommendable' => false,
        'lat' => 50.95, 'lng' => 6.95, 'price_range' => null, 'tags' => ['access' => 'yes']]);
    $unreviewed = Spot::factory()->create(['category' => $category, 'is_recommendable' => false,
        'lat' => 50.95, 'lng' => 6.95, 'price_range' => null, 'tags' => ['access' => 'yes']]);
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($place->id, ['activity_discovery' => true]);
    $review->apply($place->id, ['activity_discovery' => true], $preview['fingerprint'],
        'Current source identity, category, geometry and explicit public access checked.', 'readiness-test');
    $repository = app(CandidateRepository::class);
    $constraints = activityWindow(['categories' => [$category], 'activities' => [], 'budget' => null]);
    $candidates = $repository->candidatesFor($constraints, 50.95, 6.95);
    expect(array_column($candidates, 'id'))->toBe(["spot:{$place->id}"])
        ->and($candidates[0]->placeFacts['activities'])->toBe([])
        ->and($candidates[0]->placeFacts['fee']['value'])->toBe('unknown')
        ->and($candidates[0]->costTier)->toBe('unknown')
        ->and($place->fresh()->is_recommendable)->toBeFalse()
        ->and($repository->candidatesFor(activityWindow(['categories' => [$category], 'activities' => [], 'budget' => 'free']), 50.95, 6.95))->toBe([])
        ->and($repository->candidatesFor(activityWindow(['categories' => [], 'activities' => [], 'budget' => null]), 50.95, 6.95))->toBe([]);
    $place->update(['tags' => ['access' => 'private']]);
    expect($repository->candidatesFor($constraints, 50.95, 6.95))->toBe([]);
})->with(['playground', 'pitch', 'table_tennis']);

test('a mixed broad and fine request qualifies only the explicitly selected facility category', function () {
    $review = app(ReviewPlaceFacts::class);
    $places = [];
    foreach (['playground' => 'Playground', 'picnic' => 'Picnic area'] as $category => $name) {
        $place = Spot::factory()->create(['name' => $name, 'category' => $category, 'is_recommendable' => false,
            'lat' => 50.95, 'lng' => 6.95, 'price_range' => null, 'tags' => ['access' => 'yes']]);
        $preview = $review->preview($place->id, ['activity_discovery' => true]);
        $review->apply($place->id, ['activity_discovery' => true], $preview['fingerprint'],
            'This public activity facility has been reviewed against its current source.', 'readiness-test');
        $places[$category] = $place;
    }
    $constraints = activityWindow(['categories' => ['park', 'playground'], 'activities' => [], 'budget' => null]);
    expect(array_column(app(CandidateRepository::class)->candidatesFor($constraints, 50.95, 6.95), 'id'))->toBe(['spot:'.$places['playground']->id]);
});

test('a later access review invalidates qualification until a fresh public review', function () {
    $place = activityPitch(['is_recommendable' => false]);
    $review = app(ReviewPlaceFacts::class);
    $changes = ['activity_discovery' => true];
    $preview = $review->preview($place->id, $changes);
    $review->apply($place->id, $changes, $preview['fingerprint'], 'Verified public source geometry for this football facility.', 'readiness-test');
    $repository = app(CandidateRepository::class);
    $constraints = activityWindow(['activities' => [], 'budget' => null]);
    expect(array_column($repository->candidatesFor($constraints, 50.95, 6.95), 'id'))->toBe(['spot:'.$place->id]);
    $preview = $review->preview($place->id, ['access' => 'unknown']);
    $review->apply($place->id, ['access' => 'unknown'], $preview['fingerprint'], 'Later evidence no longer confirms public access to this facility.', 'other-reviewer');
    expect($repository->candidatesFor($constraints, 50.95, 6.95))->toBe([])
        ->and($repository->byIds(['spot:'.$place->id], CarbonImmutable::now()))->toBe([]);
    $preview = $review->preview($place->id, ['access' => null]);
    $review->apply($place->id, ['access' => null], $preview['fingerprint'], 'Withdraw the access review while keeping its audit history.', 'other-reviewer');
    expect($repository->candidatesFor($constraints, 50.95, 6.95))->toBe([]);
    $preview = $review->preview($place->id, $changes);
    $review->apply($place->id, $changes, $preview['fingerprint'], 'Rechecked current source and public access after the access review.', 'readiness-test');
    expect(array_column($repository->candidatesFor($constraints, 50.95, 6.95), 'id'))->toBe(['spot:'.$place->id]);
});
