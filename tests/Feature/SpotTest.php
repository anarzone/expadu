<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('places page renders the placeholder', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('explore'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('places'));
});

test('veedel rail is a short teaser while the chips list is exhaustive', function () {
    $user = User::factory()->onboarded()->create(['veedel' => 'Veedel-01']);
    $this->actingAs($user);

    foreach (range(1, 10) as $i) {
        $name = sprintf('Veedel-%02d', $i);
        DB::table('veedels')->insert([
            'name' => $name,
            'bezirk' => 'Test',
            'centroid_lat' => 50.93 + $i * 0.01,
            'centroid_lng' => 6.95,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Spot::factory()->create(['category' => 'park', 'veedel' => $name, 'lat' => 50.93 + $i * 0.01, 'lng' => 6.95]);
    }

    // Partial reload evaluates the deferred props.
    $props = $this->get(route('explore'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'places',
        'X-Inertia-Partial-Data' => 'veedels,allVeedels',
    ])->assertOk()->json('props');

    expect(count($props['veedels']))->toBe(6);
    expect($props['veedels'][0]['name'])->toBe('Veedel-01'); // home pinned first
    expect($props['allVeedels'])->toHaveCount(10);
    expect($props['allVeedels'])->toBe(collect($props['allVeedels'])->sort()->values()->all());
});

test('places page requires onboarding', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->get(route('explore'))->assertRedirect(route('onboarding'));
});
