<?php

use App\Composer\Actions\ComposerActions;
use App\Enums\GermanLevel;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Composer changing their settings (coordinator, 24 Oct 2026: slice 2): a kind of notification,
 * their step-free need and their profile, each as Settings keeps it, with Undo, and Ask first
 * holding it. Email stays out, and what a value is stays out of what the AI is told.
 */
beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->user = User::factory()->onboarded()->create(['needs_step_free' => false, 'german_level' => GermanLevel::A2]);
    $this->actions = app(ComposerActions::class);
});

test('🔒 a kind of notification is turned off and back with Undo, the others untouched', function () {
    $made = $this->actions->request($this->user, 'set_notifications', ['kind' => 'weather', 'on' => false], CarbonImmutable::now());

    expect($made['blocks'][0])->toMatchArray(['type' => 'done', 'text' => 'Weather alerts are off.'])
        ->and($this->user->fresh()->wantsNotification('weather'))->toBeFalse()
        ->and($this->user->fresh()->wantsNotification('transit'))->toBeTrue()
        ->and($this->user->fresh()->wantsNotification('brief'))->toBeTrue();

    $this->actions->undo($this->user, $made['blocks'][0]['id'], CarbonImmutable::now());
    expect($this->user->fresh()->wantsNotification('weather'))->toBeTrue();
});

test('🔒 the step-free need is set with Undo, and held for a tap while they ask first', function () {
    $made = $this->actions->request($this->user, 'set_needs', ['step_free' => true], CarbonImmutable::now());
    expect($made['blocks'][0]['type'])->toBe('done')
        ->and($this->user->fresh()->needs_step_free)->toBeTrue();
    $this->actions->undo($this->user, $made['blocks'][0]['id'], CarbonImmutable::now());
    expect($this->user->fresh()->needs_step_free)->toBeFalse();

    $this->user->userSetting()->updateOrCreate(['user_id' => $this->user->id], ['settings' => ['composer_ask_first_everyday' => true]]);
    $proposed = $this->actions->request($this->user->fresh(), 'set_needs', ['step_free' => true], CarbonImmutable::now());
    expect($proposed['blocks'][0])->toMatchArray(['type' => 'proposal', 'title' => 'Plan only step-free ways for you?'])
        ->and($this->user->fresh()->needs_step_free)->toBeFalse();
    $this->actingAs($this->user)->postJson('/composer/apply', ['token' => $proposed['blocks'][0]['token']])->assertSuccessful();
    expect($this->user->fresh()->needs_step_free)->toBeTrue();
});

test('🔒 a profile field is set by its own rules, its value kept out of what the AI is told; email is never changed', function () {
    $made = $this->actions->request($this->user, 'update_profile', ['field' => 'german_level', 'value' => 'B1'], CarbonImmutable::now());

    expect($made['blocks'][0]['text'])->toBe('German level: B1 — Intermediate.')
        ->and($made['said'])->not->toContain('B1')
        ->and($this->user->fresh()->german_level)->toBe(GermanLevel::B1);
    $this->actions->undo($this->user, $made['blocks'][0]['id'], CarbonImmutable::now());
    expect($this->user->fresh()->german_level)->toBe(GermanLevel::A2);

    $email = $this->user->email;
    $refused = $this->actions->request($this->user, 'update_profile', ['field' => 'email', 'value' => 'new@example.invalid'], CarbonImmutable::now());
    $wrong = $this->actions->request($this->user, 'update_profile', ['field' => 'situation', 'value' => 'astronaut'], CarbonImmutable::now());
    expect($refused['blocks'])->toBe([])->and($refused['said'])->toContain('Settings › Your account')
        ->and($wrong['blocks'])->toBe([])
        ->and($this->user->fresh()->email)->toBe($email)
        ->and($this->user->fresh()->situation)->toBe($this->user->situation);
});
