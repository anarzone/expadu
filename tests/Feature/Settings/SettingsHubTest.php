<?php

use App\Models\User;
use App\Models\UserPlace;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Settings, direction A (owner, 26 Oct 2026): one page, every section at /settings/{section}.

test('settings and each of its sections are one page, the section named', function (?string $section) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get($section === null ? '/settings' : "/settings/{$section}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings')
            ->where('section', $section)
            ->has('notifications.quiet_from')
            ->where('work', null)
            ->where('todayRank', false));
})->with([null, 'account', 'city', 'suggestions', 'composer', 'notifications', 'transit', 'data', 'delete']);

test('an unknown section is not found', function () {
    $this->actingAs(User::factory()->create())->get('/settings/appearance')->assertNotFound();
});

test('Your data and Delete account need a verified email', function (string $section) {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get("/settings/{$section}")->assertRedirect(route('verification.notice'));
})->with(['data', 'delete']);

test('the old addresses lead to their sections', function (string $from, string $to) {
    $this->actingAs(User::factory()->create())->get($from)->assertRedirect($to);
})->with([
    'the profile' => ['/settings/profile', '/settings/account'],
    'security' => ['/settings/security', '/settings/account'],
    'privacy' => ['/settings/privacy', '/settings/transit'],
]);

test('the way home reads Work’s leaving time', function () {
    $user = User::factory()->create();
    $work = UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'work']);

    $this->actingAs($user)->get('/settings/notifications')
        ->assertInertia(fn (Assert $page) => $page->where('work', ['leave_at' => null]));

    DB::table('user_places')->where('id', $work->id)->update(['leave_at' => '17:30:00']);
    $this->actingAs($user)->get('/settings/notifications')
        ->assertInertia(fn (Assert $page) => $page->where('work', ['leave_at' => '17:30']));
});

test('a single settings toggle persists without name/email (partial profile PATCH)', function () {
    $user = User::factory()->create(['has_deutschlandticket' => false]);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['has_deutschlandticket' => true])
        ->assertRedirect();

    expect($user->fresh()->has_deutschlandticket)->toBeTrue();
});

test('a guest cannot open settings', function (string $path) {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/settings', '/settings/transit', '/settings/privacy']);

test('a ticket toggle receives a JSON acknowledgement only after it persists', function () {
    $user = User::factory()->create(['has_deutschlandticket' => false]);

    $this->actingAs($user)
        ->patchJson(route('profile.update'), ['has_deutschlandticket' => true])
        ->assertOk()
        ->assertJson(['saved' => true]);

    expect($user->fresh()->has_deutschlandticket)->toBeTrue();
});
