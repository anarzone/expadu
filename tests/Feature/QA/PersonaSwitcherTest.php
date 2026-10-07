<?php

use App\Models\User;
use App\Models\UserTask;

test('an admin can preview a persona without changing their account', function () {
    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $before = $admin->fresh()->getRawOriginal();
    $this->getJson('/bureaucracy/v2/preview/neu-student?jurisdiction=de-nrw-cologne')
        ->assertSuccessful()->assertJsonPath('read_only', true)->assertJsonPath('facts.values.purpose', 'study');
    expect($admin->fresh()->getRawOriginal())->toBe($before);
});

test('a planning preview leaves actual arrival unknown', function () {
    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $preview = $this->getJson('/bureaucracy/v2/preview/planning?jurisdiction=de-nrw-cologne')
        ->assertSuccessful()->assertJsonPath('facts.values.arrival_planned', true)->json();
    expect($preview['facts']['values'])->not->toHaveKey('arrival_date');
});

test('switching case previews does not leave saved scenario answers behind', function () {
    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $this->getJson('/bureaucracy/v2/preview/case-family-renewal-four-years?jurisdiction=de-nrw-cologne')
        ->assertSuccessful()->assertJsonPath('facts.values.current_residence_title', 'family_reunification');
    $student = $this->getJson('/bureaucracy/v2/preview/neu-student?jurisdiction=de-nrw-cologne')->assertSuccessful()->json();
    expect($student['facts']['values'])->not->toHaveKey('family_residence_permit_held_since')
        ->and($admin->bureaucracyCase()->count())->toBe(0);
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

test('the old QA reset cannot discard saved task progress', function () {
    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $this->actingAs($admin);

    $task = UserTask::factory()->completed()->create(['user_id' => $admin->id]);
    $before = $task->fresh()->getRawOriginal();
    $this->postJson(route('qa.reset-tasks'))->assertGone();
    expect($task->fresh()->getRawOriginal())->toBe($before);
});

test('a non-admin cannot reset task progress', function () {
    $user = User::factory()->onboarded()->create(['is_admin' => false]);
    $this->actingAs($user);

    $this->post(route('qa.reset-tasks'))->assertForbidden();
});
