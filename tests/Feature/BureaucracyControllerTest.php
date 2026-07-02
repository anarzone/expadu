<?php

use App\Models\User;
use App\Services\BuergeramtService;

test('bureaucracy page renders with slot data for onboarded user', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('bureaucracy'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('bureaucracy')
        ->has('slots')
    );
});

test('bureaucracy page includes correct slot structure', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('bureaucracy'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('bureaucracy')
        ->where('slots', function ($slots) {
            foreach ($slots as $key => $slot) {
                expect($key)->toBeIn(array_keys(BuergeramtService::OFFICES));
                expect($slot)->toHaveKeys(['name', 'address', 'status', 'next_slot', 'booking_url']);
            }

            return true;
        })
    );
});

test('bureaucracy page requires authentication', function () {
    $this->get(route('bureaucracy'))
        ->assertRedirect(route('login'));
});
