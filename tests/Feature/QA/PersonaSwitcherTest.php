<?php

use App\Models\User;
use App\Models\UserTask;

test('an admin can become a persona with real writes', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->post(route('qa.become', ['persona' => 'neu-student']))
        ->assertRedirect();

    $admin->refresh();

    expect($admin->situation->value)->toBe('student')
        ->and($admin->is_eu)->toBeFalse()
        ->and($admin->profile_attributes['qa_persona'] ?? null)->toBe('neu-student')
        ->and($admin->onboarded_at)->not->toBeNull();
});

test('becoming a planning persona leaves the arrival date empty', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->post(route('qa.become', ['persona' => 'planning']))
        ->assertRedirect();

    expect($admin->refresh()->arrival_date)->toBeNull();
});

test('becoming a case persona synchronizes confirmed facts and switching away retires them', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->post(route('qa.become', ['persona' => 'case-family-renewal-four-years']))->assertRedirect();

    $case = $admin->refresh()->bureaucracyCase;
    expect($case)->not->toBeNull()
        ->and($case->facts()->where('state', 'confirmed')->where('source', 'qa_scenario:case-family-renewal-four-years')->exists())->toBeTrue();

    $this->post(route('qa.become', ['persona' => 'neu-student']))->assertRedirect();

    expect($case->facts()->where('state', 'confirmed')->where('source', 'like', 'qa_scenario:%')->exists())->toBeFalse();
});

test('a non-admin cannot become a persona', function () {
    $user = User::factory()->onboarded()->create(['is_admin' => false]);
    $this->actingAs($user);

    $this->post(route('qa.become', ['persona' => 'neu-student']))
        ->assertForbidden();
});

test('becoming an unknown persona 404s', function () {
    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->post(route('qa.become', ['persona' => 'does-not-exist']))
        ->assertNotFound();
});

test('an admin can reset their task progress', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->post(route('qa.become', ['persona' => 'neu-student']))->assertRedirect();
    expect(UserTask::where('user_id', $admin->id)->count())->toBeGreaterThan(0);

    $this->post(route('qa.reset-tasks'))->assertRedirect();

    expect(UserTask::where('user_id', $admin->id)->count())->toBe(0);
});

test('a non-admin cannot reset task progress', function () {
    $user = User::factory()->onboarded()->create(['is_admin' => false]);
    $this->actingAs($user);

    $this->post(route('qa.reset-tasks'))->assertForbidden();
});
