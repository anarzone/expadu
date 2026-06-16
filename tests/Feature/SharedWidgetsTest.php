<?php

use App\Models\User;

it('renders app pages with the shared right-panel widgets, no error', function () {
    $user = User::factory()->create([
        'onboarded_at' => now(),
        'situation' => 'student',
        'veedel' => 'Ehrenfeld',
    ]);

    // share() (which now defers the city-widget props) runs on every Inertia
    // request — these must not 500.
    $this->actingAs($user)->get('/composer')->assertOk();
    $this->actingAs($user)->get('/dashboard')->assertOk();
});
