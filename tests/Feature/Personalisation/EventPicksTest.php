<?php

use App\Models\User;
use App\Personalisation\EventPicks;
use App\Personalisation\PersonalisationWording;
use Illuminate\Support\Facades\DB;

/*
 * "What would you go to?": the kinds of event a person picks themselves. Personalisation's: only
 * with it on under the wording that names picks, and erased when it's turned off.
 */

function picker(): User
{
    $user = User::factory()->onboarded()->create();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk();

    return $user->fresh();
}

function putPicks(User $user, array $body)
{
    return test()->withToken($user->createToken('iPhone')->plainTextToken)->putJson('/api/v1/me/picks', $body);
}

test('picks are kept as they tap, and Today stops asking once they say Done', function () {
    $user = picker();

    putPicks($user, ['kinds' => ['music', 'kids'], 'done' => false])->assertOk()
        ->assertJsonPath('picks', ['kinds' => ['music', 'kids'], 'done' => false]);
    $today = test()->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/today')->assertOk()->json('picks');
    expect($today['ask'])->toBeTrue()->and($today['picked'])->toBe(['music', 'kids'])
        ->and(collect($today['kinds'])->pluck('key')->all())->toBe(EventPicks::kinds())
        ->and(collect($today['kinds'])->pluck('key')->all())->not->toContain('other');

    putPicks($user, ['kinds' => ['music', 'kids', 'tours'], 'done' => true])->assertOk();
    expect(test()->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/today')->json('picks.ask'))->toBeFalse()
        ->and(test()->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/memory')->json('picks'))->toBe(['kinds' => ['music', 'kids', 'tours'], 'done' => true]);
});

test('only the kinds there are, each once', function () {
    $user = picker();

    putPicks($user, ['kinds' => ['other'], 'done' => false])->assertUnprocessable();
    putPicks($user, ['kinds' => ['music', 'music'], 'done' => false])->assertUnprocessable();
    putPicks($user, ['kinds' => ['astrology'], 'done' => false])->assertUnprocessable();
});

test('🔒 with personalisation off, nothing is kept and Today doesn’t ask', function () {
    $user = User::factory()->onboarded()->create();

    putPicks($user, ['kinds' => ['music'], 'done' => false])->assertUnprocessable();
    expect(DB::table('users')->where('id', $user->id)->value('interests'))->toBeNull()
        ->and(test()->withToken($user->createToken('iPhone')->plainTextToken)->getJson('/api/v1/today')->json('picks'))->toBeNull();
});

test('🔒 on under an earlier wording, picks wait until they turn it on again', function () {
    $user = picker();
    $user->userSetting()->update(['settings' => ['personalisation' => true, PersonalisationWording::SETTING => EventPicks::WORDING - 1]]);

    putPicks($user->fresh(), ['kinds' => ['music'], 'done' => false])->assertUnprocessable();
    expect(app(EventPicks::class)->of($user->fresh()))->toBeNull();
});

test('🔒 turning personalisation off erases their picks', function () {
    $user = picker();
    putPicks($user, ['kinds' => ['music', 'tours'], 'done' => true])->assertOk();

    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();

    expect(DB::table('users')->where('id', $user->id)->value('interests'))->toBeNull();
    // On again: asked afresh, nothing picked.
    $user = picker();
    expect(app(EventPicks::class)->of($user))->toBe(['kinds' => [], 'done' => false]);
});
