<?php

use App\Models\User;
use App\Personalisation\TodayRankPause;

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
