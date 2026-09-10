<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\PlanSlot;
use App\Composer\TodayPlanStore;
use App\Home\DiscoveryFeed;
use App\Models\Spot;
use App\Models\User;
use App\Places\DestinationGrouping;
use App\Places\ReconcilePlace;
use App\Services\NearbyPlaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function destinationFixture(): array
{
    $parent = Spot::factory()->create(['name' => 'Test destination', 'category' => 'park', 'lat' => 50.95, 'lng' => 6.95]);
    $child = Spot::factory()->create(['name' => 'Tennis facility', 'category' => 'tennis', 'parent_spot_id' => $parent->id, 'park_name' => $parent->name, 'lat' => 50.951, 'lng' => 6.951]);

    return [$parent, $child];
}

function reviewDestination(Spot $child, ?Spot $parent): void
{
    $service = app(DestinationGrouping::class);
    $preview = $service->preview($child->id, $parent?->id);
    $service->review($child->id, $parent?->id, 'Reviewed source evidence confirms the operation and access relationship.', $preview['fingerprint']);
}

test('only reviewed component membership groups a destination without changing facility identity', function () {
    [$parent, $child] = destinationFixture();
    $policy = app(DestinationGrouping::class);
    $original = $child->only(['id', 'lat', 'lng', 'parent_spot_id', 'source_id']);
    expect($policy->general(Spot::query())->pluck('id')->all())->toContain($parent->id, $child->id);
    reviewDestination($child, $parent);
    expect($policy->general(Spot::query())->pluck('id')->all())->toBe([$parent->id])
        ->and($policy->eligible(Spot::query())->pluck('id')->all())->toContain($parent->id, $child->id)
        ->and($policy->groupIds([$parent->id, $child->id]))->toBe([$parent->id => $parent->id, $child->id => $parent->id])
        ->and($child->fresh()->only(array_keys($original)))->toBe($original)
        ->and(DB::table('place_destination_reviews')->count())->toBe(1);
});

test('reviewed independent venues and unresolved park names remain discoverable', function () {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, null);
    $museum = Spot::factory()->create(['category' => 'museum', 'park_name' => 'Unresolved park']);
    expect(app(DestinationGrouping::class)->general(Spot::query())->pluck('id')->all())->toContain($parent->id, $child->id, $museum->id)
        ->and($child->fresh()->destination_reviewed_at)->not->toBeNull();
});

test('review evidence cannot be stale or empty', function () {
    [$parent, $child] = destinationFixture();
    $service = app(DestinationGrouping::class);
    $preview = $service->preview($child->id, $parent->id);
    $child->update(['name' => 'Changed facility']);
    expect(fn () => $service->review($child->id, $parent->id, 'Reviewed source evidence confirms this component.', $preview['fingerprint']))->toThrow(DomainException::class)
        ->and(fn () => $service->review($child->id, $parent->id, '', $service->preview($child->id, $parent->id)['fingerprint']))->toThrow(DomainException::class)
        ->and(DB::table('place_destination_reviews')->count())->toBe(0);
});

test('membership rejects invalid parents and cycles', function (string $scenario) {
    [$parent, $child] = destinationFixture();
    match ($scenario) {
        'self' => $parent = $child,
        'not_destination' => $parent->update(['category' => 'cafe']),
        'wrong_containment' => $child->update(['parent_spot_id' => null]),
        'inactive' => $parent->update(['is_active' => false]),
    };
    expect(fn () => reviewDestination($child, $parent))->toThrow(DomainException::class);
})->with(['self', 'not_destination', 'wrong_containment', 'inactive']);

test('parent restrictions and changed containment fail closed without erasing reviewed membership', function (string $change) {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, $parent);
    match ($change) {
        'inactive' => $parent->update(['is_active' => false]),
        'restricted' => $parent->update(['is_recommendable' => false]),
        'moved' => $child->update(['parent_spot_id' => null]),
        'child_restricted' => $child->update(['is_recommendable' => false]),
    };
    expect(app(DestinationGrouping::class)->eligible(Spot::query())->pluck('id')->all())->not->toContain($child->id)
        ->and($child->fresh()->destination_spot_id)->toBe($parent->id)
        ->and(DB::table('place_destination_reviews')->count())->toBe(1);
})->with(['inactive', 'restricted', 'moved', 'child_restricted']);

test('reviewed membership is preserved when its destination identity is reconciled', function () {
    [$parent, $child] = destinationFixture();
    $canonical = Spot::factory()->create(['name' => $parent->name, 'category' => 'park', 'lat' => $parent->lat, 'lng' => $parent->lng, 'source' => 'osm', 'source_id' => 'way/123456']);
    reviewDestination($child, $parent);
    $identity = app(ReconcilePlace::class);
    $preview = $identity->preview($parent->id, $canonical->id);
    $identity->apply($parent->id, $canonical->id, $preview['fingerprint'], 'Reviewed identical destination source identity.');
    expect(app(DestinationGrouping::class)->groupIds([$child->id]))->toBe([$child->id => $canonical->id])
        ->and(app(DestinationGrouping::class)->eligible(Spot::query())->pluck('id')->all())->toContain($child->id)
        ->and(DB::table('place_destination_reviews')->count())->toBe(1);
});

test('reviewed membership cannot be removed by catalogue replacement or deletion', function () {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, $parent);
    expect(fn () => $child->delete())->toThrow(DomainException::class);
    $this->artisan('spots:load', ['--force' => true])->assertFailed();
    expect(Spot::find($child->id))->not->toBeNull();
});

test('places coarse browse groups reviewed activities while fine activity selection retains the facility location', function () {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, $parent);
    $this->actingAs(User::factory()->onboarded()->create());
    $browse = $this->getJson('/api/places?category=court')->assertSuccessful()->json('data');
    expect(array_column($browse, 'id'))->toBe([$parent->id])
        ->and(array_column($browse[0]['activities'], 'label'))->toBe(['Tennis court']);
    $activity = $this->getJson('/api/places?activity=tennis')->assertSuccessful()->json('data');
    expect(array_column($activity, 'id'))->toBe([$child->id])
        ->and($activity[0]['lat'])->toBe($child->lat)
        ->and($activity[0]['lng'])->toBe($child->lng);
    $this->getJson('/api/places?activity=not-a-category')->assertUnprocessable();
});

test('unreviewed containment and independent culture places stay visible in Places', function () {
    [$parent, $child] = destinationFixture();
    $museum = Spot::factory()->create(['category' => 'museum', 'parent_spot_id' => $parent->id, 'park_name' => $parent->name, 'lat' => 50.951, 'lng' => 6.952]);
    reviewDestination($museum, null);
    $this->actingAs(User::factory()->onboarded()->create());
    expect(array_column($this->getJson('/api/places')->assertSuccessful()->json('data'), 'id'))->toContain($parent->id, $child->id, $museum->id);
});

test('restricted activities neither advertise destination chips nor pass explicit filtering', function () {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, $parent);
    $child->update(['is_recommendable' => false]);
    $this->actingAs(User::factory()->onboarded()->create());
    $this->getJson('/api/places?activity=tennis')->assertSuccessful()->assertJsonCount(0, 'data');
    $this->getJson('/api/places?category=court')->assertSuccessful()->assertJsonCount(0, 'data');
    $this->getJson("/api/places/{$parent->id}")->assertSuccessful()->assertJsonCount(0, 'data.activities');
});

test('Composer suppresses components before ranking but retains explicitly requested or pinned facilities', function () {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, $parent);
    $window = new Constraints(CarbonImmutable::parse('2026-09-11 10:00', 'Europe/Berlin'), CarbonImmutable::parse('2026-09-11 18:00', 'Europe/Berlin'));
    $repository = app(CandidateRepository::class);
    $general = collect($repository->candidatesFor($window));
    expect($general->pluck('id')->all())->toContain("spot:{$parent->id}")->not->toContain("spot:{$child->id}");
    $explicit = collect($repository->candidatesFor($window->withCategories(['tennis', 'basketball'])))->firstWhere('id', "spot:{$child->id}");
    expect($explicit)->not->toBeNull()
        ->and($explicit->destinationGroupId)->toBe("spot:{$parent->id}")
        ->and($explicit->lat)->toBe($child->lat);
    $pin = $repository->byIds(["spot:{$child->id}"], $window->windowStart)[0];
    expect($pin->id)->toBe("spot:{$child->id}")
        ->and($pin->destinationGroupId)->toBe("spot:{$parent->id}")
        ->and($pin->lng)->toBe($child->lng);
    $parent->update(['is_active' => false]);
    expect($repository->byIds(["spot:{$child->id}"], $window->windowStart))->toBe([]);
});

test('a canonical destination owning components through an alias cannot become a component', function () {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, $parent);
    $canonical = Spot::factory()->create(['name' => $parent->name, 'category' => 'park', 'lat' => $parent->lat, 'lng' => $parent->lng, 'source' => 'osm', 'source_id' => 'way/876']);
    $identity = app(ReconcilePlace::class);
    $preview = $identity->preview($parent->id, $canonical->id);
    $identity->apply($parent->id, $canonical->id, $preview['fingerprint'], 'Reviewed identical destination source identity.');
    $outer = Spot::factory()->create(['category' => 'park']);
    $canonical->update(['parent_spot_id' => $outer->id]);
    expect(fn () => reviewDestination($canonical, $outer))->toThrow(DomainException::class)
        ->and(DB::table('place_destination_reviews')->count())->toBe(1)
        ->and($canonical->fresh()->destination_spot_id)->toBeNull();
});

test('membership command previews by default and requires the exact fingerprint to apply', function () {
    [$parent, $child] = destinationFixture();
    $this->artisan('places:review-destination', ['spot' => $child->id, 'destination' => $parent->id])->assertSuccessful();
    expect(DB::table('place_destination_reviews')->count())->toBe(0);
    $this->artisan('places:review-destination', ['spot' => $child->id, 'destination' => $parent->id, '--apply' => true, '--evidence' => 'Reviewed source evidence confirms component operation.', '--fingerprint' => 'wrong'])->assertFailed();
    $preview = app(DestinationGrouping::class)->preview($child->id, $parent->id);
    $this->artisan('places:review-destination', ['spot' => $child->id, 'destination' => $parent->id, '--apply' => true, '--evidence' => 'Reviewed source evidence confirms component operation.', '--fingerprint' => $preview['fingerprint']])->assertSuccessful();
    expect($child->fresh()->destination_spot_id)->toBe($parent->id);
});

test('reviewing membership invalidates warm Home scans and nearest general discovery suppresses components', function () {
    [$parent, $child] = destinationFixture();
    $user = User::factory()->onboarded()->create();
    app(DiscoveryFeed::class)->for(homeContext($user));
    expect(array_column(Cache::get('discovery:spot-scan:identity:0:grouping:0'), 'id'))->toContain($child->id);
    reviewDestination($child, $parent);
    app(DiscoveryFeed::class)->for(homeContext($user));
    $revision = app(DestinationGrouping::class)->revision();
    expect(array_column(Cache::get("discovery:spot-scan:identity:0:grouping:$revision"), 'id'))->not->toContain($child->id)
        ->and(app(NearbyPlaces::class)->nearest(50.95, 6.95, 20)->modelKeys())->toBe([$parent->id]);
});

test('saved child slots retain coordinates and timing and prevent a sibling from returning during a swap', function () {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, $parent);
    $sibling = Spot::factory()->create(['name' => 'Sibling tennis', 'category' => 'tennis', 'parent_spot_id' => $parent->id, 'lat' => 50.951, 'lng' => 6.951]);
    reviewDestination($sibling, $parent);
    $cafe = Spot::factory()->create(['name' => 'First café', 'category' => 'cafe', 'lat' => 50.951, 'lng' => 6.951]);
    $alternative = Spot::factory()->create(['name' => 'Another café', 'category' => 'cafe', 'lat' => 50.951, 'lng' => 6.951]);
    $user = User::factory()->onboarded()->create();
    $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(10, 0);
    $window = new Constraints($start, $start->addHours(8), categories: ['tennis', 'cafe']);
    $candidates = collect(app(CandidateRepository::class)->byIds(["spot:{$child->id}", "spot:{$cafe->id}"], $start))->keyBy('id');
    $slots = [(new PlanSlot($candidates["spot:{$child->id}"], $start, $start->addHour(), 0))->toArray(), (new PlanSlot($candidates["spot:{$cafe->id}"], $start->addHours(2), $start->addHours(3), 0))->toArray()];
    $plan = ['constraints' => $window->toArray(), 'slots' => $slots, 'pins' => ["spot:{$child->id}"], 'origin' => [50.951, 6.951]];
    Cache::put("composer:plan:{$user->id}", $plan, 3600);
    app(TodayPlanStore::class)->save($user, $plan, 'Tennis and coffee');
    expect(app(TodayPlanStore::class)->get($user)['slots'][0])->toBe($slots[0]);
    $this->actingAs($user)->postJson('/composer/swap', ['slot' => 1])->assertSuccessful();
    $stored = Cache::get("composer:plan:{$user->id}");
    expect($stored['slots'][0]['id'])->toBe("spot:{$child->id}")
        ->and($stored['slots'][0]['lat'])->toBe($child->lat)
        ->and($stored['slots'][0]['lng'])->toBe($child->lng)
        ->and($stored['slots'][0]['start_at'])->toBe($slots[0]['start_at'])
        ->and($stored['slots'][0]['end_at'])->toBe($slots[0]['end_at'])
        ->and($stored['slots'][1]['id'])->toBe("spot:{$alternative->id}");
});

test('explicit activity results retain distinct facility coordinates even within the same name cluster', function () {
    [$parent, $child] = destinationFixture();
    reviewDestination($child, $parent);
    $second = Spot::factory()->create(['name' => $child->name, 'category' => 'tennis', 'parent_spot_id' => $parent->id, 'lat' => 50.9511, 'lng' => 6.9511]);
    reviewDestination($second, $parent);
    $this->actingAs(User::factory()->onboarded()->create());
    $rows = $this->getJson('/api/places?activity=tennis')->assertSuccessful()->json('data');
    expect(array_column($rows, 'id'))->toContain($child->id, $second->id)
        ->and(collect($rows)->firstWhere('id', $second->id)['lat'])->toBe($second->lat);
});

test('a complex cannot crowd an independent facility out of the general Composer pool cap', function () {
    [$parent, $first] = destinationFixture();
    reviewDestination($first, $parent);
    foreach (range(1, 12) as $index) {
        $child = Spot::factory()->create(['name' => "Component $index", 'category' => 'tennis', 'parent_spot_id' => $parent->id, 'lat' => 50.951, 'lng' => 6.951]);
        reviewDestination($child, $parent);
    }
    $independent = Spot::factory()->create(['name' => 'Independent tennis club', 'category' => 'tennis', 'lat' => 50.96, 'lng' => 6.96]);
    $start = CarbonImmutable::parse('2026-09-11 10:00', 'Europe/Berlin');
    $pool = app(CandidateRepository::class)->candidatesFor(new Constraints($start, $start->addHours(8)), 50.951, 6.951);
    expect(array_column($pool, 'id'))->toContain("spot:{$independent->id}")
        ->and(collect($pool)->where('category', 'tennis')->count())->toBe(1);
});
