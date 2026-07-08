<?php

use App\Models\User;

test('the profile stat counts days SINCE arrival, not a negative diff', function () {
    // Regression: now()->diffInDays(arrival) returned a NEGATIVE for a past
    // arrival ("-267 DAYS"). A user who arrived 100 days ago has been here 100.
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(100)->startOfDay(),
    ]);

    $this->actingAs($user)
        ->get(route('profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('profile')
            ->where('stats.days_in_germany', 100)
        );
});

test('a not-yet-arrived date never shows a negative day count', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->addDays(30)->startOfDay(),
    ]);

    $this->actingAs($user)
        ->get(route('profile'))
        ->assertInertia(fn ($page) => $page->where('stats.days_in_germany', 0));
});

test('no arrival date leaves the day count null', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => null]);

    $this->actingAs($user)
        ->get(route('profile'))
        ->assertInertia(fn ($page) => $page->where('stats.days_in_germany', null));
});
