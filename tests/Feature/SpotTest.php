<?php

use App\Models\Spot;
use App\Models\User;
use App\Models\UserPlace;

test('explore page renders with spots', function () {
    $user = User::factory()->onboarded()->create();
    Spot::factory()->count(3)->create();
    $this->actingAs($user);

    $response = $this->get(route('explore', ['veedel' => 'all']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('explore')
        ->missing('spots')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('spots.data', 3)
        )
    );
});

test('explore defaults to the user home veedel', function () {
    $user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld']);
    Spot::factory()->count(2)->create(['veedel' => 'Ehrenfeld']);
    Spot::factory()->create(['veedel' => 'Nippes']);
    $this->actingAs($user);

    $response = $this->get(route('explore'));

    $response->assertInertia(fn ($page) => $page
        ->where('filters.veedel', 'Ehrenfeld')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('spots.data', 2)
        )
    );
});

test('explore page includes personal places', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->count(2)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $response = $this->get(route('explore'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('explore')
        ->has('personalPlaces', 2)
    );
});

test('spots can be filtered by category', function () {
    $user = User::factory()->onboarded()->create();
    Spot::factory()->create(['category' => 'cafe']);
    Spot::factory()->create(['category' => 'library']);
    $this->actingAs($user);

    $response = $this->get(route('explore', ['category' => 'cafe', 'veedel' => 'all']));

    $response->assertInertia(fn ($page) => $page
        ->missing('spots')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('spots.data', 1)
        )
    );
});
