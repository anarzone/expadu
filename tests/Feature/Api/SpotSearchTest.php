<?php

use App\Models\Spot;
use App\Models\User;
use App\Places\DestinationGrouping;
use App\Places\ReviewPlaceFacts;

test('spot search survives a spot sitting exactly on the origin', function () {
    // With no home place the controller's origin is 50.9375 / 6.9603. A spot at
    // that exact point makes the great-circle acos argument round to 1.0±epsilon;
    // without the [-1, 1] clamp Postgres errors on it and the endpoint 500s.
    $user = User::factory()->onboarded()->create();
    Spot::factory()->create([
        'name' => 'Bang On Origin',
        'category' => 'park',
        'lat' => 50.9375,
        'lng' => 6.9603,
    ]);

    $response = $this->actingAs($user)->getJson('/api/spots?'.http_build_query([
        'sw_lat' => 50.90, 'ne_lat' => 50.97,
        'sw_lng' => 6.90, 'ne_lng' => 7.00,
    ]));

    $response->assertOk();
    expect(collect($response->json())->pluck('name'))->toContain('Bang On Origin');
});

test('map discovery groups reviewed facilities while a category request retains their locations', function () {
    $parent = Spot::factory()->create(['category' => 'park', 'lat' => 50.94, 'lng' => 6.95]);
    $court = Spot::factory()->create(['category' => 'tennis', 'parent_spot_id' => $parent->id, 'lat' => 50.941, 'lng' => 6.951]);
    $policy = app(DestinationGrouping::class);
    $preview = $policy->preview($court->id, $parent->id);
    $policy->review($court->id, $parent->id, 'Reviewed source confirms this tennis facility belongs to the park.', $preview['fingerprint']);
    $independent = Spot::factory()->create(['category' => 'museum', 'parent_spot_id' => $parent->id, 'lat' => 50.942, 'lng' => 6.952]);
    $url = '/api/spots?'.http_build_query(['sw_lat' => 50.90, 'ne_lat' => 50.97, 'sw_lng' => 6.90, 'ne_lng' => 7.00]);
    $this->actingAs(User::factory()->onboarded()->create());
    expect(array_column($this->getJson($url)->assertSuccessful()->json(), 'id'))->toContain($parent->id, $independent->id)->not->toContain($court->id);
    $this->getJson($url.'&category=tennis')->assertSuccessful()->assertJsonCount(1)->assertJsonPath('0.id', $court->id)->assertJsonPath('0.lat', $court->lat)->assertJsonPath('0.lng', $court->lng);
});

test('map activity requests obey facility and destination eligibility', function (string $change) {
    $parent = Spot::factory()->create(['category' => 'park', 'lat' => 50.94, 'lng' => 6.95]);
    $court = Spot::factory()->create(['category' => 'tennis', 'parent_spot_id' => $parent->id, 'lat' => 50.941, 'lng' => 6.951]);
    $policy = app(DestinationGrouping::class);
    $preview = $policy->preview($court->id, $parent->id);
    $policy->review($court->id, $parent->id, 'Reviewed source confirms this tennis facility belongs to the park.', $preview['fingerprint']);
    match ($change) {
        'parent_inactive' => $parent->update(['is_active' => false]),
        'parent_restricted' => $parent->update(['is_recommendable' => false]),
        'child_restricted' => $court->update(['is_recommendable' => false]),
        'containment_changed' => $court->update(['parent_spot_id' => null]),
    };
    $this->actingAs(User::factory()->onboarded()->create())->getJson('/api/spots?'.http_build_query(['sw_lat' => 50.90, 'ne_lat' => 50.97, 'sw_lng' => 6.90, 'ne_lng' => 7.00, 'category' => 'tennis']))->assertSuccessful()->assertJsonCount(0);
})->with(['parent_inactive', 'parent_restricted', 'child_restricted', 'containment_changed']);

test('a map facility category includes only current qualified descriptive records', function () {
    $place = Spot::factory()->create(['name' => 'Playground', 'category' => 'playground',
        'source' => 'osm', 'source_id' => 'way/98765432', 'is_recommendable' => false,
        'lat' => 50.94, 'lng' => 6.95, 'price_range' => null, 'tags' => ['access' => 'yes']]);
    Spot::factory()->create(['category' => 'playground', 'is_recommendable' => false, 'lat' => 50.941, 'lng' => 6.951]);
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($place->id, ['activity_discovery' => true]);
    $review->apply($place->id, ['activity_discovery' => true], $preview['fingerprint'],
        'Current source category and public playground geometry have been checked.', 'readiness-test');
    $url = '/api/spots?'.http_build_query(['sw_lat' => 50.90, 'ne_lat' => 50.97, 'sw_lng' => 6.90, 'ne_lng' => 7.00]);
    $this->actingAs(User::factory()->onboarded()->create());
    $this->getJson($url)->assertSuccessful()->assertJsonCount(0);
    $this->getJson($url.'&category=playground')->assertSuccessful()->assertJsonCount(1)->assertJsonPath('0.id', $place->id);
    $place->update(['tags' => ['access' => 'private']]);
    $this->getJson($url.'&category=playground')->assertSuccessful()->assertJsonCount(0);
});

test('a later unknown access review removes a qualified facility from map category search', function () {
    $place = Spot::factory()->create(['category' => 'playground', 'is_recommendable' => false,
        'lat' => 50.94, 'lng' => 6.95, 'tags' => ['access' => 'yes']]);
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($place->id, ['activity_discovery' => true]);
    $review->apply($place->id, ['activity_discovery' => true], $preview['fingerprint'], 'Verified current public source and playground geometry.', 'readiness-test');
    $url = '/api/spots?'.http_build_query(['sw_lat' => 50.90, 'ne_lat' => 50.97, 'sw_lng' => 6.90, 'ne_lng' => 7.00, 'category' => 'playground']);
    $this->actingAs(User::factory()->onboarded()->create());
    $this->getJson($url)->assertSuccessful()->assertJsonCount(1)->assertJsonPath('0.id', $place->id);
    $preview = $review->preview($place->id, ['access' => 'unknown']);
    $review->apply($place->id, ['access' => 'unknown'], $preview['fingerprint'], 'Later evidence cannot confirm public access to this playground.', 'other-reviewer');
    $this->getJson($url)->assertSuccessful()->assertJsonCount(0);
});
