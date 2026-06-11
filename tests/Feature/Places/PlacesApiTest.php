<?php

use App\Models\Spot;
use App\Models\User;
use App\Models\UserPlace;

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
    // basketball rolls up to the coarse 'court' bucket
    $response->assertJsonPath('data.0.category', 'court');
    $response->assertJsonPath('data.0.open_now', true);
    $response->assertJsonPath('data.0.price_text', 'free');
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

    expect($this->getJson('/api/places?veedel=Ehrenfeld')->json('meta.total'))->toBe(1);
    expect($this->getJson('/api/places?veedel=all')->json('meta.total'))->toBe(2);
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
