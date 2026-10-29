<?php

use App\Models\User;
use App\Personalisation\PersonalisationWording;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Settings › Privacy (owner, 29 Oct 2026, the consent canvas's board 1): personalisation is for
 * people 16 or older, the yes is kept with its wording and date, and the page says what was learned.
 */
test('🔒 turning personalisation on needs their word that they’re 16 or older', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true]])
        ->assertUnprocessable()->assertJsonValidationErrors(['sixteen_or_older']);
    $this->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => false])->assertUnprocessable();
    expect($user->fresh()->personalises())->toBeFalse();

    $this->travelTo(now()->setTime(9, 30));
    $settings = $this->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk()->json('settings');

    expect($user->fresh()->personalises())->toBeTrue()
        ->and($settings[PersonalisationWording::SETTING])->toBe(PersonalisationWording::CURRENT)
        ->and($settings[PersonalisationWording::AGREED_AT])->toBe(now()->toIso8601String());

    // Off forgets the yes with what was learned; other settings need no age.
    $off = $this->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk()->json('settings');
    expect($off)->not->toHaveKey(PersonalisationWording::AGREED_AT);
    $this->putJson('/user-settings', ['settings' => ['share_location' => false]])->assertOk();
});

test('Privacy says what was learned, and nothing while it’s off', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/settings/privacy')->assertInertia(fn (Assert $page) => $page->where('learned', null));

    $this->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk();
    $this->actingAs($user->fresh())->get('/settings/privacy')
        ->assertInertia(fn (Assert $page) => $page->where('learned', ['actions' => 0, 'leanings' => [], 'ordered_at' => null]));
});
