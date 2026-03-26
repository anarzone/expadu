<?php

use App\Models\SlotMonitor;
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
        ->has('monitors')
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
                expect($slot)->toHaveKeys(['name', 'address', 'status', 'next_slot', 'slots_today']);
            }

            return true;
        })
    );
});

test('bureaucracy page includes active monitors for authenticated user', function () {
    $user = User::factory()->onboarded()->create();

    SlotMonitor::factory()->create([
        'user_id' => $user->id,
        'office_id' => 'deutz',
        'is_active' => true,
    ]);

    SlotMonitor::factory()->create([
        'user_id' => $user->id,
        'office_id' => 'kalk',
        'is_active' => false,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('bureaucracy'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('bureaucracy')
        ->where('monitors', ['deutz'])
    );
});

test('bureaucracy page requires authentication', function () {
    $this->get(route('bureaucracy'))
        ->assertRedirect(route('login'));
});
