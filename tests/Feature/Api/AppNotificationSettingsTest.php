<?php

use App\Models\User;

/*
 * Their notifications on the iPhone (Settings › Notifications), kept as the web keeps them: which
 * kinds, quiet hours, the brief's time. Only their own; one toggle never resets the rest.
 */

test('🔒 notification settings need a token, and each person reads and changes only their own', function () {
    $this->getJson('/api/v1/notifications/settings')->assertUnauthorized();
    $this->putJson('/api/v1/notifications/settings', ['preferences' => ['brief' => false]])->assertUnauthorized();

    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $this->withToken($mine->createToken('iPhone')->plainTextToken)
        ->putJson('/api/v1/notifications/settings', ['preferences' => ['brief' => false]])->assertOk();
    app('auth')->forgetGuards();

    $this->withToken($theirs->createToken('iPhone')->plainTextToken)
        ->getJson('/api/v1/notifications/settings')->assertOk()->assertJsonPath('preferences.brief', true);
});

test('one kind turned off keeps the rest; quiet hours and the brief time are checked as on the web', function () {
    $as = $this->withToken(User::factory()->create()->createToken('iPhone')->plainTextToken);

    $as->getJson('/api/v1/notifications/settings')->assertOk()
        ->assertJsonPath('preferences.events', true)->assertJsonPath('quiet_from', '22:00')->assertJsonPath('brief_at', null);

    $as->putJson('/api/v1/notifications/settings', ['preferences' => ['weather' => false]])->assertOk()
        ->assertJsonPath('preferences.weather', false)->assertJsonPath('preferences.events', true);

    $as->putJson('/api/v1/notifications/settings', ['quiet_from' => '23:00', 'quiet_to' => '23:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('quiet_from');
    $as->putJson('/api/v1/notifications/settings', ['brief_at' => '23:30'])
        ->assertUnprocessable()->assertJsonValidationErrors('brief_at');

    $as->putJson('/api/v1/notifications/settings', ['quiet_from' => '21:30', 'quiet_to' => '06:30', 'brief_at' => '07:15'])->assertOk()
        ->assertJsonPath('quiet_from', '21:30')->assertJsonPath('quiet_to', '06:30')->assertJsonPath('brief_at', '07:15')
        ->assertJsonPath('preferences.weather', false);
});
