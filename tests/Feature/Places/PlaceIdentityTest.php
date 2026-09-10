<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\PlanSlot;
use App\Composer\TodayPlanStore;
use App\Home\DiscoveryFeed;
use App\Http\Controllers\ReviewController;
use App\Media\PublishedMediaSelector;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\Review;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Models\Venue;
use App\Places\PlaceIdentity;
use App\Places\PlaceIdentityAudit;
use App\Places\ReconcilePlace;
use App\Services\UserLocationService;
use App\Services\VeedelDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function identityPair(): array
{
    $attributes = ['name' => 'Testgarten', 'category' => 'park', 'lat' => 50.95, 'lng' => 6.95, 'is_active' => true, 'is_recommendable' => true];

    return [
        Spot::factory()->create($attributes),
        Spot::factory()->create([...$attributes, 'source' => 'osm', 'source_id' => 'way/42']),
    ];
}

function reconcileIdentity(Spot $alias, Spot $canonical): void
{
    $service = app(ReconcilePlace::class);
    $preview = $service->preview($alias->id, $canonical->id);
    $service->apply($alias->id, $canonical->id, $preview['fingerprint'], 'Reviewed source history and mapped footprint: these records identify the same destination.');
}

test('explicit reconciliation retains source records and normalizes old references', function () {
    [$alias, $canonical] = identityPair();
    $original = $alias->only(['name', 'source', 'source_id', 'lat', 'lng']);
    reconcileIdentity($alias, $canonical);

    expect(Spot::query()->count())->toBe(2)
        ->and($alias->fresh()->only(array_keys($original)))->toBe($original)
        ->and($alias->fresh()->canonical_spot_id)->toBe($canonical->id)
        ->and(Spot::query()->recommendationEligible()->pluck('id')->all())->toBe([$canonical->id])
        ->and((new Spot)->resolveRouteBinding($alias->id)->id)->toBe($canonical->id)
        ->and((new Spot)->resolveRouteBinding(9999999))->toBeNull()
        ->and(app(PlaceIdentity::class)->candidateIds(["spot:{$alias->id}", "spot:{$canonical->id}", 'event:42', 'spot:9999999']))
        ->toBe(["spot:{$canonical->id}", 'event:42', 'spot:9999999'])
        ->and(DB::table('place_reconciliations')->count())->toBe(1);
});

test('reconciliation is idempotent and reactivation cannot restore an alias to discovery', function () {
    [$alias, $canonical] = identityPair();
    reconcileIdentity($alias, $canonical);
    reconcileIdentity($alias, $canonical);
    $alias->refresh()->update(['is_active' => true, 'is_recommendable' => true]);

    expect(DB::table('place_reconciliations')->count())->toBe(1)
        ->and(Spot::query()->recommendationEligible()->pluck('id')->all())->toBe([$canonical->id]);
});

test('reconciliation rejects changed evidence and leaves all references untouched', function () {
    [$alias, $canonical] = identityPair();
    $service = app(ReconcilePlace::class);
    $preview = $service->preview($alias->id, $canonical->id);
    $canonical->update(['name' => 'Changed after preview']);

    expect(fn () => $service->apply($alias->id, $canonical->id, $preview['fingerprint'], 'Reviewed matching source history and footprint.'))->toThrow(DomainException::class)
        ->and($alias->fresh()->canonical_spot_id)->toBeNull()
        ->and(DB::table('place_reconciliations')->count())->toBe(0);
});

test('reconciliation rejects unsafe identity mappings', function (string $scenario) {
    [$alias, $canonical] = identityPair();
    match ($scenario) {
        'different_category' => $canonical->update(['category' => 'basketball']),
        'distant' => $canonical->update(['lat' => 50.96]),
        'sourced_alias' => $alias->update(['source' => 'osm', 'source_id' => 'node/42']),
        'inactive_target' => $canonical->update(['is_active' => false]),
        'self_containment' => $canonical->update(['parent_spot_id' => $alias->id]),
        'self_link' => $canonical = $alias,
    };

    expect(fn () => reconcileIdentity($alias, $canonical))->toThrow(DomainException::class)
        ->and(DB::table('place_reconciliations')->count())->toBe(0);
})->with(['different_category', 'distant', 'sourced_alias', 'inactive_target', 'self_containment', 'self_link']);

test('reconciliation cannot create an alias chain or move an existing alias', function () {
    [$alias, $canonical] = identityPair();
    reconcileIdentity($alias, $canonical);
    $other = Spot::factory()->create(['name' => 'Testgarten', 'category' => 'park', 'lat' => 50.95, 'lng' => 6.95]);

    expect(fn () => reconcileIdentity($other, $alias))->toThrow(DomainException::class)
        ->and(fn () => reconcileIdentity($canonical, $other))->toThrow(DomainException::class)
        ->and(fn () => reconcileIdentity($alias, $other))->toThrow(DomainException::class)
        ->and($alias->fresh()->canonical_spot_id)->toBe($canonical->id);
});

test('reconciliation preserves and remaps child references atomically', function () {
    [$alias, $canonical] = identityPair();
    $child = Spot::factory()->create(['category' => 'basketball', 'parent_spot_id' => $alias->id]);
    reconcileIdentity($alias, $canonical);

    expect($child->fresh()->parent_spot_id)->toBe($canonical->id)
        ->and(DB::table('place_reconciliations')->sole()->evidence)->toContain('Reviewed source history');
});

test('old saved feedback survives canonical detail and clearing removes the whole family', function () {
    [$alias, $canonical] = identityPair();
    $user = User::factory()->onboarded()->create();
    SpotFeedback::factory()->create(['user_id' => $user->id, 'spot_id' => $alias->id, 'state' => 'saved']);
    reconcileIdentity($alias, $canonical);
    $this->actingAs($user)->getJson("/api/places/{$alias->id}")->assertOk()
        ->assertJsonPath('data.id', $canonical->id)->assertJsonPath('data.feedback_state', 'saved');
    $this->postJson("/api/places/{$alias->id}/feedback", ['action' => 'clear'])->assertOk();

    expect(SpotFeedback::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('latest family feedback wins and new canonical feedback supersedes older aliases', function () {
    [$alias, $canonical] = identityPair();
    $user = User::factory()->onboarded()->create();
    SpotFeedback::factory()->create(['user_id' => $user->id, 'spot_id' => $canonical->id, 'state' => 'not_interested', 'updated_at' => now()->subDays(2)]);
    SpotFeedback::factory()->create(['user_id' => $user->id, 'spot_id' => $alias->id, 'state' => 'saved', 'updated_at' => now()->subDay()]);
    reconcileIdentity($alias, $canonical);
    $this->actingAs($user)->getJson('/api/places')->assertOk()->assertJsonPath('data.0.feedback_state', 'saved');
    $this->postJson("/api/places/{$alias->id}/feedback", ['action' => 'been'])->assertOk();
    $this->getJson("/api/places/{$canonical->id}")->assertOk()->assertJsonPath('data.feedback_state', 'been');

    expect(SpotFeedback::query()->count())->toBe(2);
});

test('family reviews preserve history and count the newest review once per user in ratings', function () {
    [$alias, $canonical] = identityPair();
    $user = User::factory()->onboarded()->create();
    Review::factory()->create(['spot_id' => $canonical->id, 'user_id' => $user->id, 'rating' => 1, 'updated_at' => now()->subDays(2)]);
    Review::factory()->create(['spot_id' => $alias->id, 'user_id' => $user->id, 'rating' => 5, 'updated_at' => now()->subDay()]);
    Review::factory()->create(['spot_id' => $alias->id, 'rating' => 3]);
    reconcileIdentity($alias, $canonical);

    expect($canonical->fresh()->rating)->toBe(4.0);
    $this->actingAs($user)->getJson("/explore/{$alias->id}/reviews")->assertOk()->assertJsonPath('total', 2);
    $this->postJson("/explore/{$alias->id}/reviews", ['rating' => 3, 'body' => 'Updated review'])->assertCreated();

    expect($canonical->fresh()->rating)->toBe(3.0)
        ->and(Review::query()->count())->toBe(3);
});

test('family media selection preserves rights and canonical manual preference', function (bool $eager) {
    [$alias, $canonical] = identityPair();
    $asset = MediaAsset::factory()->approved()->create();
    $alias->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'hero', 'is_manually_locked' => true]);
    reconcileIdentity($alias, $canonical);
    if ($eager) {
        $canonical->load('mediaAttachments.mediaAsset');
    }
    $selector = app(PublishedMediaSelector::class);

    expect($selector->hasManagedMedia($canonical))->toBeTrue()
        ->and($selector->select($canonical)?->id)->toBe($asset->id);

    $own = MediaAsset::factory()->approved()->create();
    $canonical->mediaAttachments()->create(['media_asset_id' => $own->id, 'role' => 'hero', 'is_manually_locked' => true]);
    expect($selector->select($canonical->fresh())?->id)->toBe($own->id);
})->with([false, true]);

test('pending or broken alias media cannot fall through to legacy photos', function (string $rights, string $health) {
    [$alias, $canonical] = identityPair();
    $canonical->update(['photo_url' => 'https://legacy.example/unverified.jpg']);
    $asset = MediaAsset::factory()->create(['rights_status' => $rights, 'health_status' => $health]);
    $alias->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'hero']);
    reconcileIdentity($alias, $canonical);
    $this->actingAs(User::factory()->onboarded()->create())->getJson("/api/places/{$canonical->id}")
        ->assertOk()->assertJsonPath('data.photo_url', null);
    $card = collect(app(DiscoveryFeed::class)->for(homeContext(User::factory()->onboarded()->create())))
        ->flatMap(fn ($rail) => $rail['cards'])->firstWhere('id', "spot:{$canonical->id}");
    expect($card)->not->toBeNull()->and($card['photo_url'])->toBeNull();
})->with([['pending', 'active'], ['approved', 'broken']]);

test('map search and Composer resolve one canonical destination', function () {
    [$alias, $canonical] = identityPair();
    reconcileIdentity($alias, $canonical);
    $this->actingAs(User::factory()->onboarded()->create());
    $map = $this->getJson('/api/spots?sw_lat=50.9&sw_lng=6.9&ne_lat=51&ne_lng=7')->assertOk()->json();
    expect(array_column($map, 'id'))->toBe([$canonical->id]);
    $candidates = app(CandidateRepository::class)->byIds(["spot:{$alias->id}"], CarbonImmutable::now());
    expect(array_map(fn ($candidate) => $candidate->id, $candidates))->toBe(["spot:{$canonical->id}"]);
});

test('reconciliation invalidates warm discovery cells and preserves alias suppression', function () {
    [$alias, $canonical] = identityPair();
    $user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld']);
    $feed = app(DiscoveryFeed::class);
    $context = homeContext($user, ['originLat' => 50.95, 'originLng' => 6.95]);
    $ids = fn () => collect($feed->for($context))->flatMap(fn ($rail) => $rail['cards'])->pluck('id')->all();
    expect($ids())->toContain("spot:{$alias->id}");
    reconcileIdentity($alias, $canonical);
    expect($ids())->not->toContain("spot:{$alias->id}")->toContain("spot:{$canonical->id}");
    SpotFeedback::factory()->create(['spot_id' => $alias->id, 'user_id' => $user->id, 'state' => 'not_interested']);
    expect($ids())->not->toContain("spot:{$canonical->id}");
});

test('neighbourhood directories count canonical destinations only', function () {
    [$alias, $canonical] = identityPair();
    DB::table('veedels')->insert(['name' => 'Test area', 'bezirk' => 'Test district']);
    $alias->update(['veedel' => 'Test area']);
    $canonical->update(['veedel' => 'Test area']);
    reconcileIdentity($alias, $canonical);
    expect(app(VeedelDirectory::class)->bezirkRail(null)[0]['count'])->toBe(1);
    $canonical->update(['is_recommendable' => false]);
    expect(app(VeedelDirectory::class)->veedelsByBezirk())->toBe([]);
});

test('catalogue replacement and pruning refuse to destroy retained identity references', function () {
    [$alias, $canonical] = identityPair();
    reconcileIdentity($alias, $canonical);
    $this->artisan('spots:prune', ['--category' => ['park'], '--force' => true])->assertFailed();
    $this->artisan('spots:load', ['--force' => true])->assertFailed();
    expect(Spot::query()->count())->toBe(2)->and(DB::table('place_reconciliations')->count())->toBe(1);
});

test('saved Composer snapshots normalize identity without changing timing or other candidate types', function () {
    [$alias, $canonical] = identityPair();
    $user = User::factory()->onboarded()->create();
    $slot = ['id' => "spot:{$alias->id}", 'name' => $alias->name, 'start_at' => now()->addHour()->toIso8601String(), 'end_at' => now()->addHours(2)->toIso8601String(), 'travel_min_from_previous' => 7];
    $plan = ['slots' => [$slot], 'pins' => ["spot:{$alias->id}"], 'excluded' => ["spot:{$alias->id}"], 'rejected' => [["spot:{$alias->id}", 'event:42']]];
    app(TodayPlanStore::class)->save($user, $plan, 'Saved before reconciliation');
    reconcileIdentity($alias, $canonical);
    $normalized = app(PlaceIdentity::class)->normalizePlan($plan);

    expect($normalized['slots'][0])->toBe([...$slot, 'id' => "spot:{$canonical->id}"])
        ->and($normalized['pins'])->toBe(["spot:{$canonical->id}"])
        ->and($normalized['excluded'])->toBe(["spot:{$canonical->id}"])
        ->and($normalized['rejected'][0])->toBe(["spot:{$canonical->id}", 'event:42'])
        ->and(app(TodayPlanStore::class)->get($user)['slots'][0])->toBe($normalized['slots'][0]);
});

test('Composer honors old identity pins locks and exclusions after reconciliation', function () {
    [$alias, $canonical] = identityPair();
    $user = User::factory()->onboarded()->create();
    app(UserLocationService::class)->confirm($user, 50.95, 6.95, 'Origin');
    reconcileIdentity($alias, $canonical);
    $start = now('Europe/Berlin')->addDay()->setTime(12, 0);
    $constraints = ['window_start' => $start->toIso8601String(), 'window_end' => $start->addHours(5)->toIso8601String()];
    $this->actingAs($user)->postJson('/composer/compose', ['constraints' => $constraints, 'locked' => ["spot:{$alias->id}"]])->assertOk();
    $stored = Cache::get("composer:plan:{$user->id}");
    expect($stored['pins'])->toBe(["spot:{$canonical->id}"])
        ->and(array_column($stored['slots'], 'id'))->toContain("spot:{$canonical->id}");
    $this->postJson('/composer/compose', ['constraints' => $constraints, 'pins' => ["spot:{$alias->id}"], 'excluded' => ["spot:{$alias->id}"]])->assertOk();
    $stored = Cache::get("composer:plan:{$user->id}");
    expect($stored['excluded'])->toBe(["spot:{$canonical->id}"])
        ->and(array_column($stored['slots'], 'id'))->not->toContain("spot:{$canonical->id}");
});

test('reconciliation command previews first and requires reviewed evidence and fingerprint to apply', function () {
    [$alias, $canonical] = identityPair();
    $args = ['alias' => $alias->id, 'canonical' => $canonical->id];
    expect(Artisan::call('places:reconcile-identity', $args))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($alias->fresh()->canonical_spot_id)->toBeNull();
    $this->artisan('places:reconcile-identity', [...$args, '--apply' => true])->assertFailed();
    $this->artisan('places:reconcile-identity', [...$args, '--apply' => true, '--fingerprint' => $preview['fingerprint'], '--evidence' => 'Reviewed map footprint and source history confirming one destination.'])->assertSuccessful();

    expect($alias->fresh()->canonical_spot_id)->toBe($canonical->id)
        ->and(app(PlaceIdentityAudit::class)->report()['candidates'])->toBe([]);
});

test('deleting a retained identity cannot remove its media before the foreign key refuses deletion', function () {
    [$alias, $canonical] = identityPair();
    $alias->mediaAttachments()->create(['media_asset_id' => MediaAsset::factory()->approved()->create()->id]);
    reconcileIdentity($alias, $canonical);
    expect(fn () => $alias->delete())->toThrow(DomainException::class)
        ->and($alias->mediaAttachments()->count())->toBe(1)
        ->and(Spot::query()->count())->toBe(2);
});

test('events and destination context remain accessible through the old place URL', function () {
    [$alias, $canonical] = identityPair();
    $venue = Venue::create(['name' => 'Testgarten', 'place_id' => $alias->id, 'lat' => 50.95, 'lng' => 6.95]);
    Event::factory()->create(['title' => 'Garden concert', 'venue_id' => $venue->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2), 'recurrence' => null]);
    $child = Spot::factory()->create(['name' => 'Garden court', 'category' => 'basketball', 'parent_spot_id' => $alias->id, 'destination_spot_id' => $alias->id, 'destination_reviewed_parent_id' => $alias->id, 'destination_reviewed_at' => now(), 'destination_grouping_evidence' => 'Reviewed component fixture', 'lat' => 50.9501, 'lng' => 6.95]);
    reconcileIdentity($alias, $canonical);
    $this->actingAs(User::factory()->onboarded()->create());
    foreach ([$alias, $canonical] as $place) {
        $this->getJson("/api/places/{$place->id}/events")->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.venue.place_id', $canonical->id);
        $response = $this->getJson("/api/places/{$place->id}/context")->assertOk();
        expect(json_encode($response->json()))->toContain('Garden court');
    }
    expect($child->fresh()->parent_spot_id)->toBe($canonical->id);
});

test('repeating an existing canonical choice supersedes newer alias feedback and reviews', function () {
    [$alias, $canonical] = identityPair();
    $user = User::factory()->onboarded()->create();
    SpotFeedback::factory()->create(['spot_id' => $canonical->id, 'user_id' => $user->id, 'state' => 'saved', 'rating' => null, 'updated_at' => now()->subDays(2)]);
    SpotFeedback::factory()->create(['spot_id' => $alias->id, 'user_id' => $user->id, 'state' => 'not_interested', 'updated_at' => now()->subDay()]);
    Review::factory()->create(['spot_id' => $canonical->id, 'user_id' => $user->id, 'rating' => 3, 'body' => 'Same review', 'updated_at' => now()->subDays(2)]);
    Review::factory()->create(['spot_id' => $alias->id, 'user_id' => $user->id, 'rating' => 1, 'updated_at' => now()->subDay()]);
    reconcileIdentity($alias, $canonical);
    $this->actingAs($user)->postJson("/api/places/{$alias->id}/feedback", ['action' => 'saved'])->assertOk();
    $this->getJson("/api/places/{$canonical->id}")->assertOk()->assertJsonPath('data.feedback_state', 'saved');
    $this->postJson("/explore/{$alias->id}/reviews", ['rating' => 3, 'body' => 'Same review'])->assertCreated();
    expect($canonical->fresh()->rating)->toBe(3.0);
});

test('an unavailable canonical place cannot silently remove a saved slot or shift the swap target', function () {
    [$alias, $canonical] = identityPair();
    $user = User::factory()->onboarded()->create();
    $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(12, 0);
    $constraints = new Constraints($start, $start->addHours(5));
    $candidate = app(CandidateRepository::class)->byIds(["spot:{$alias->id}"], $start)[0];
    $slot = new PlanSlot($candidate, $start, $start->addHour(), 0);
    $plan = ['constraints' => $constraints->toArray(), 'slots' => [$slot->toArray()], 'origin' => [50.95, 6.95]];
    Cache::put("composer:plan:{$user->id}", $plan, 3600);
    reconcileIdentity($alias, $canonical);
    $canonical->update(['is_recommendable' => false]);

    $this->actingAs($user)->postJson('/composer/swap', ['slot' => 0])->assertConflict();
    expect(Cache::get("composer:plan:{$user->id}"))->toBe($plan);
    $this->postJson('/composer/compose', ['constraints' => $constraints->toArray(), 'pins' => ["spot:{$alias->id}"]])->assertUnprocessable();
});

test('a request bound before reconciliation writes its review to the current canonical identity', function () {
    [$alias, $canonical] = identityPair();
    $boundBeforeReconciliation = Spot::query()->findOrFail($alias->id);
    $user = User::factory()->onboarded()->create();
    reconcileIdentity($alias, $canonical);
    $request = Request::create('/review', 'POST', ['rating' => 4, 'body' => 'Current choice']);
    $request->setUserResolver(fn () => $user);
    app(ReviewController::class)->store($request, $boundBeforeReconciliation);

    expect(Review::query()->sole()->spot_id)->toBe($canonical->id)
        ->and($canonical->fresh()->rating)->toBe(4.0);
});

test('swapping a later slot preserves an old identity outside the nearest candidate pool', function () {
    [$alias, $canonical] = identityPair();
    $alias->update(['lat' => 50.953]);
    $canonical->update(['lat' => 50.953]);
    $cafe = Spot::factory()->create(['name' => 'Original cafe', 'category' => 'cafe', 'lat' => 50.95, 'lng' => 6.95]);
    Spot::factory()->create(['name' => 'Alternative cafe', 'category' => 'cafe', 'lat' => 50.95001, 'lng' => 6.95]);
    foreach (range(1, 13) as $index) {
        Spot::factory()->create(['name' => "Nearby park {$index}", 'category' => 'park', 'lat' => 50.9501, 'lng' => 6.95]);
    }
    $user = User::factory()->onboarded()->create();
    $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(12, 0);
    $constraints = new Constraints($start, $start->addHours(5));
    $repository = app(CandidateRepository::class);
    $old = $repository->byIds(["spot:{$alias->id}"], $start)[0];
    $second = $repository->byIds(["spot:{$cafe->id}"], $start)[0];
    $firstSlot = (new PlanSlot($old, $start, $start->addHour(), 0))->toArray();
    $secondSlot = (new PlanSlot($second, $start->addHour()->addMinutes(10), $start->addHours(2)->addMinutes(10), 10))->toArray();
    Cache::put("composer:plan:{$user->id}", ['constraints' => $constraints->toArray(), 'slots' => [$firstSlot, $secondSlot], 'origin' => [50.95, 6.95]], 3600);
    reconcileIdentity($alias, $canonical);
    expect(array_column($repository->candidatesFor($constraints, 50.95, 6.95), 'id'))->not->toContain("spot:{$canonical->id}");
    $response = $this->actingAs($user)->postJson('/composer/swap', ['slot' => 1])->assertOk();
    $response->assertJsonPath('plan.slots.0.id', "spot:{$canonical->id}")
        ->assertJsonPath('plan.slots.0.start_at', $firstSlot['start_at'])
        ->assertJsonPath('plan.slots.0.end_at', $firstSlot['end_at']);
    expect($response->json('plan.slots.1.id'))->not->toBe("spot:{$cafe->id}");
});
