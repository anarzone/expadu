<?php

use App\Models\Spot;
use App\Models\User;
use App\Models\UserPlace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld']);
    UserPlace::factory()->create([
        'user_id' => $this->user->id,
        'category' => 'home',
        'lat' => 50.948,
        'lng' => 6.921,
    ]);
    $this->actingAs($this->user);
});

test('lists leisure places with the full contract shape', function () {
    Spot::factory()->create([
        'name' => 'Grüngürtel court',
        'category' => 'basketball',
        'veedel' => 'Ehrenfeld',
        'lat' => 50.949,
        'lng' => 6.922,
    ]);

    $response = $this->getJson('/api/places');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'name', 'category', 'veedel', 'lat', 'lng', 'photo_url', 'distance_min', 'open_now', 'opening_hours_text', 'price_text', 'feature_chips', 'tip', 'transit_hint', 'facts']],
        'meta' => ['total'],
    ]);
    // basketball rolls up to the coarse 'court' bucket but keeps its fine identity
    $response->assertJsonPath('data.0.category', 'court');
    $response->assertJsonPath('data.0.fine_label', 'Basketball court');
    $response->assertJsonPath('data.0.emoji', '🏀');
    $response->assertJsonPath('data.0.open_now', true);
    $response->assertJsonPath('data.0.price_text', 'free');
    // no per-place tip stored → the category fallback is marked generic
    $response->assertJsonPath('data.0.tip_is_generic', true);
});

test('excludes indoor/legacy categories from Places', function () {
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);

    $response = $this->getJson('/api/places');

    expect(collect($response->json('data'))->pluck('category')->all())->not->toContain('other');
    expect(collect($response->json('data'))->pluck('category')->all())->toContain('park');
});

test('filters by coarse category', function () {
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    Spot::factory()->create(['category' => 'tennis', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);

    $response = $this->getJson('/api/places?category=court');

    $cats = collect($response->json('data'))->pluck('category')->unique()->all();
    expect($cats)->toBe(['court']);
});

test('filters by veedel and lifts the filter with all', function () {
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Nippes', 'lat' => 50.96, 'lng' => 6.95]);

    // No veedels centroid seeded → strict filter, no "& nearby" claim.
    $response = $this->getJson('/api/places?veedel=Ehrenfeld');
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('nearby_included'))->toBeFalse();
    expect($this->getJson('/api/places?veedel=all')->json('meta.total'))->toBe(2);
});

test('includes places within 2km of the veedel centroid as nearby', function () {
    DB::table('veedels')->insert([
        'name' => 'Ehrenfeld',
        'bezirk' => 'Ehrenfeld',
        'centroid_lat' => 50.949,
        'centroid_lng' => 6.917,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // In the selected Veedel, but farther from the user's home anchor…
    $inVeedel = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.941, 'lng' => 6.905]);
    // …than this neighbouring-Veedel place ~1km from the Ehrenfeld centroid
    $nearby = Spot::factory()->create(['category' => 'park', 'veedel' => 'Neuehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);
    // Other side of the city, well outside 2km → excluded
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Porz', 'lat' => 50.886, 'lng' => 7.058]);

    $response = $this->getJson('/api/places?veedel=Ehrenfeld');

    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('nearby_included'))->toBeTrue();
    // The selected Veedel's own places rank above nearby ones, even when
    // the nearby place is closer to the user's home.
    expect($response->json('data.0.id'))->toBe($inVeedel->id);
    expect($response->json('data.1.id'))->toBe($nearby->id);
});

test('filters by bezirk across its stadtteile', function () {
    foreach (['Ehrenfeld' => 'Ehrenfeld', 'Neuehrenfeld' => 'Ehrenfeld', 'Nippes' => 'Nippes'] as $name => $bezirk) {
        DB::table('veedels')->insert([
            'name' => $name,
            'bezirk' => $bezirk,
            'centroid_lat' => 50.94,
            'centroid_lng' => 6.92,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Spot::factory()->create(['category' => 'park', 'veedel' => $name, 'lat' => 50.94, 'lng' => 6.92]);
    }

    $response = $this->getJson('/api/places?bezirk=Ehrenfeld');

    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('nearby_included'))->toBeFalse();
    expect(collect($response->json('data'))->pluck('veedel')->sort()->values()->all())
        ->toBe(['Ehrenfeld', 'Neuehrenfeld']);
});

test('collapses identically-named places within ~100m into one card', function () {
    // Three same-name tables in one park corner → one card, cluster_size 3
    foreach (range(1, 3) as $i) {
        Spot::factory()->create(['name' => 'Tischtennisplatte', 'category' => 'table_tennis', 'veedel' => 'Ehrenfeld', 'lat' => 50.9481, 'lng' => 6.9211]);
    }
    // Same name but a different corner of the Veedel → stays its own card
    Spot::factory()->create(['name' => 'Tischtennisplatte', 'category' => 'table_tennis', 'veedel' => 'Ehrenfeld', 'lat' => 50.9580, 'lng' => 6.9100]);

    $response = $this->getJson('/api/places?veedel=Ehrenfeld');

    expect($response->json('meta.total'))->toBe(2);
    expect(collect($response->json('data'))->pluck('cluster_size')->sort()->values()->all())->toBe([1, 3]);
});

test('rejects an unknown coarse category', function () {
    $this->getJson('/api/places?category=nightclub')->assertUnprocessable();
});

test('orders by distance from the home anchor', function () {
    $near = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);
    $far = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.99, 'lng' => 7.00]);

    $ids = collect($this->getJson('/api/places')->json('data'))->pluck('id')->all();
    expect(array_search($near->id, $ids))->toBeLessThan(array_search($far->id, $ids));
});
