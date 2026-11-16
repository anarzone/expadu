<?php

use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Models\User;
use App\Personalisation\Memory;
use App\Personalisation\PersonalisationWording;
use App\Personalisation\Pseudonym;
use App\Personalisation\TodayRankPause;
use Illuminate\Support\Facades\DB;

/* The iPhone's part in personalisation: the same signals, the same agree-again, only for a confirmed account. */

test('🔒 signals and Today’s order need a token and a confirmed address', function () {
    $signal = ['signals' => [['action' => 'not_for_me', 'surface' => 'today', 'item_type' => 'event', 'item_id' => 1]]];
    $this->postJson('/api/v1/signals', $signal)->assertUnauthorized();
    $this->putJson('/api/v1/today-rank', ['notice_version' => 'x', 'provider_version' => 'y'])->assertUnauthorized();
    $this->postJson('/api/v1/today-rank/later')->assertUnauthorized();

    $token = User::factory()->unverified()->create()->createToken('iPhone')->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/signals', $signal)->assertForbidden();
});

test('🔒 a signal names only what the web’s may: nothing else gets in', function () {
    $as = $this->withToken(User::factory()->create()->createToken('iPhone')->plainTextToken);

    $as->postJson('/api/v1/signals', ['signals' => [['action' => 'not_for_me', 'surface' => 'today', 'item_type' => 'event', 'item_id' => 1, 'note' => 'home address']]])
        ->assertUnprocessable();
    $as->postJson('/api/v1/signals', ['signals' => [['action' => 'saved', 'surface' => 'today', 'item_type' => 'event', 'item_id' => 1]]])
        ->assertUnprocessable();
});

test('agreeing to a changed notice that isn’t waiting is refused; Not now is kept a week', function () {
    $user = User::factory()->create();
    $as = $this->withToken($user->createToken('iPhone')->plainTextToken);

    $as->putJson('/api/v1/today-rank', ['notice_version' => 'v', 'provider_version' => 'p'])->assertConflict();
    $as->postJson('/api/v1/today-rank/later')->assertOk()->assertJsonStructure(['until']);
    expect(app(TodayRankPause::class)->onToday($user->fresh()))->toBeNull();
});

test('🔒 the phone turns personalisation on only with their word that they’re 16 or older, under the current wording', function () {
    $user = User::factory()->onboarded()->create();
    $as = $this->withToken($user->createToken('iPhone')->plainTextToken);

    $as->putJson('/api/v1/settings', ['settings' => ['personalisation' => true]])->assertUnprocessable()->assertJsonValidationErrors('sixteen_or_older');
    expect($user->fresh()->personalises())->toBeFalse();

    $as->putJson('/api/v1/settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk()
        ->assertJsonPath('settings.personalisation', true)
        ->assertJsonPath('settings.'.PersonalisationWording::SETTING, PersonalisationWording::CURRENT);
    // Each real request loads the account afresh; the test's guard would keep the one it read.
    app('auth')->forgetGuards();
    $as->getJson('/api/v1/memory')->assertOk()->assertJsonPath('personalised', true)->assertJsonPath('personalisation_current', true);
});

test('🔒 turning it off on the phone erases what Composer remembers and what was learned', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => true], 'sixteen_or_older' => true]);
    app(Memory::class)->remember($user->fresh(), 'Vegetarian');
    $as = $this->withToken($user->createToken('iPhone')->plainTextToken);

    $as->putJson('/api/v1/settings', ['settings' => ['personalisation' => false]])->assertOk()->assertJsonPath('settings.personalisation', false);

    expect(DB::table('user_facts')->where('subject_key', Pseudonym::for($user))->count())->toBe(0);
    app('auth')->forgetGuards();
    $as->getJson('/api/v1/memory')->assertOk()->assertJsonPath('personalised', false)->assertJsonPath('facts', []);
});

test('🔒 settings need a token and a confirmed address', function () {
    $this->putJson('/api/v1/settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertUnauthorized();
    $this->patchJson('/api/v1/me/profile', ['field' => 'german_level', 'value' => 'b1'])->assertUnauthorized();

    $token = User::factory()->unverified()->create()->createToken('iPhone')->plainTextToken;
    $this->withToken($token)->putJson('/api/v1/settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertForbidden();
});

test('the phone changes their situation and German level by the profile’s own rules, and nothing else', function () {
    $user = User::factory()->onboarded()->create(['german_level' => GermanLevel::A1, 'situation' => Situation::Student]);
    $as = $this->withToken($user->createToken('iPhone')->plainTextToken);

    $as->patchJson('/api/v1/me/profile', ['field' => 'german_level', 'value' => 'b1'])->assertOk()
        ->assertJsonPath('german_level', 'b1')->assertJsonPath('situation', 'student');
    $as->patchJson('/api/v1/me/profile', ['field' => 'situation', 'value' => 'freelancer'])->assertOk()->assertJsonPath('situation', 'freelancer');
    // The same value again is no change, not a refusal.
    $as->patchJson('/api/v1/me/profile', ['field' => 'situation', 'value' => 'freelancer'])->assertOk();

    $as->patchJson('/api/v1/me/profile', ['field' => 'situation', 'value' => 'astronaut'])->assertUnprocessable();
    $as->patchJson('/api/v1/me/profile', ['field' => 'email', 'value' => 'new@example.invalid'])->assertUnprocessable();
    expect($user->fresh())->german_level->toBe(GermanLevel::B1)->situation->toBe(Situation::Freelancer)->email->toBe($user->email);
});
