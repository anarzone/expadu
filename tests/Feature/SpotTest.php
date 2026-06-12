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

test('rail shows bezirke and chips map stadtteile per bezirk', function () {
    // Pin the Inertia asset version — CI has no Vite manifest, so the
    // partial-reload handshake would 409 with a file-derived version.
    config(['app.asset_url' => 'testing']);

    $user = User::factory()->onboarded()->create(['veedel' => 'Neuehrenfeld']);
    $this->actingAs($user);

    $stadtteile = [
        'Ehrenfeld' => ['Ehrenfeld', 'Neuehrenfeld', 'Bickendorf'],
        'Nippes' => ['Nippes', 'Mauenheim'],
    ];
    foreach ($stadtteile as $bezirk => $veedels) {
        foreach ($veedels as $i => $name) {
            DB::table('veedels')->insert([
                'name' => $name,
                'bezirk' => $bezirk,
                'centroid_lat' => 50.93 + $i * 0.01,
                'centroid_lng' => 6.95,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Spot::factory()->create(['category' => 'park', 'veedel' => $name, 'lat' => 50.93 + $i * 0.01, 'lng' => 6.95]);
        }
    }

    // Partial reload evaluates the deferred props.
    $props = $this->get(route('explore'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'places',
        'X-Inertia-Partial-Data' => 'bezirke,veedelsByBezirk',
    ])->assertOk()->json('props');

    // Rail: one card per Bezirk, the home Bezirk pinned first.
    expect(collect($props['bezirke'])->pluck('name')->all())->toBe(['Ehrenfeld', 'Nippes']);
    expect($props['bezirke'][0]['count'])->toBe(3);

    // Chips: stadtteile grouped per bezirk, A→Z.
    expect($props['veedelsByBezirk']['Ehrenfeld'])->toBe(['Bickendorf', 'Ehrenfeld', 'Neuehrenfeld']);
    expect($props['veedelsByBezirk']['Nippes'])->toBe(['Mauenheim', 'Nippes']);
});

test('default filters land on the home stadtteil inside its bezirk', function () {
    DB::table('veedels')->insert([
        'name' => 'Neuehrenfeld',
        'bezirk' => 'Ehrenfeld',
        'centroid_lat' => 50.95,
        'centroid_lng' => 6.92,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $user = User::factory()->onboarded()->create(['veedel' => 'Neuehrenfeld']);
    $this->actingAs($user);

    $this->get(route('explore'))->assertInertia(fn ($page) => $page
        ->component('places')
        ->where('filters.bezirk', 'Ehrenfeld')
        ->where('filters.veedel', 'Neuehrenfeld'));

    // An explicit bezirk in the URL beats the home default.
    $this->get(route('explore', ['bezirk' => 'Nippes']))->assertInertia(fn ($page) => $page
        ->where('filters.bezirk', 'Nippes')
        ->where('filters.veedel', null));
});

test('card showcase renders outside production', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->get(route('dev.cards'))->assertInertia(fn ($page) => $page->component('dev/cards'));
});

test('places page requires onboarding', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->get(route('explore'))->assertRedirect(route('onboarding'));
});
