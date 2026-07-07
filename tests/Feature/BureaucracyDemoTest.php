<?php

use App\Models\User;
use App\Models\UserTask;

test('the persona demo is admin-only', function () {
    $this->actingAs(User::factory()->onboarded()->create(['is_admin' => false]));

    $this->get(route('bureaucracy.demo'))->assertForbidden();
});

test('an admin can view any persona and nothing is written', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $this->actingAs(User::factory()->onboarded()->create(['is_admin' => true]));

    $this->get(route('bureaucracy.demo', ['persona' => 'neu-student']))
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];

            expect($props['preview']['active'])->toBe('neu-student')
                ->and($props['preview']['personas'])->not->toBeEmpty();

            // The synthetic persona still gets a real path: the Anmeldung root
            // plus a residence-permit card land in the attention lanes.
            $keys = collect([...$props['tasks']['active'], ...$props['tasks']['upcoming']])
                ->pluck('key');
            expect($keys)->toContain('stu.anmeldung')
                ->and($keys->contains(fn (?string $k) => $k !== null && str_contains($k, 'permit')))->toBeTrue();

            return true;
        });

    // The whole point: rendering a persona persists no rows.
    expect(UserTask::count())->toBe(0);
});

test('an unknown persona falls back to the first roster entry', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $this->actingAs(User::factory()->onboarded()->create(['is_admin' => true]));

    $this->get(route('bureaucracy.demo', ['persona' => 'does-not-exist']))
        ->assertOk()
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['preview']['active'])->toBe('eu-employee') && true);
});
