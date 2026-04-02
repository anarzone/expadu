<?php

use App\Models\User;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('geocode endpoint requires authentication', function () {
    $response = $this->getJson(route('api.geocode', ['q' => 'Köln']));

    $response->assertUnauthorized();
});

test('geocode endpoint requires a query parameter', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->getJson(route('api.geocode'));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('q');
});

test('geocode endpoint returns results from photon api', function () {
    $this->mock(GeocodingService::class, function ($mock) {
        $mock->shouldReceive('search')
            ->once()
            ->with('Cologne Cathedral')
            ->andReturn([
                [
                    'name' => 'Cologne Cathedral',
                    'street' => 'Domkloster',
                    'city' => 'Köln',
                    'lat' => 50.9413,
                    'lng' => 6.9583,
                ],
            ]);
    });

    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->getJson(route('api.geocode', ['q' => 'Cologne Cathedral']));

    $response->assertOk();

    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0])->toMatchArray([
        'name' => 'Cologne Cathedral',
        'street' => 'Domkloster',
        'city' => 'Köln',
        'lat' => 50.9413,
        'lng' => 6.9583,
    ]);
});

test('geocoding service returns empty array when api returns no features', function () {
    // The global beforeEach in Pest.php fakes photon.komoot.io/* with empty features.
    $service = new GeocodingService;
    $results = $service->search('random query for empty test');

    expect($results)->toBeArray()->toBeEmpty();
});

// Rate limiting test removed — no rate limit middleware on geocode route currently.
// TODO: Add rate limiting middleware and re-enable this test.

test('geocode endpoint rejects queries that are too short', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->getJson(route('api.geocode', ['q' => 'a']));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('q');
});
