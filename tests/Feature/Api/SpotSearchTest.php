<?php

use App\Models\Spot;
use App\Models\User;

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
