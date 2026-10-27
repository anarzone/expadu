<?php

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('returns default preferences for user without saved preferences', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $this->getJson(route('notification-preferences.show'))
        ->assertSuccessful()
        ->assertJson([
            'preferences' => NotificationPreference::defaults(),
        ]);
});

it('returns saved preferences', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    NotificationPreference::create([
        'user_id' => $user->id,
        'preferences' => ['transit' => false, 'burgeramt' => true],
    ]);
    $this->actingAs($user);

    $this->getJson(route('notification-preferences.show'))
        ->assertSuccessful()
        ->assertJson([
            'preferences' => ['transit' => false, 'burgeramt' => true],
        ]);
});

it('updates preferences', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $this->putJson(route('notification-preferences.update'), [
        'preferences' => ['transit' => false, 'events' => true],
    ])->assertSuccessful();

    expect($user->fresh()->notificationPreference->preferences)
        ->toMatchArray(['transit' => false, 'events' => true]);
});

it('creates preference on first update', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    expect($user->notificationPreference)->toBeNull();

    $this->putJson(route('notification-preferences.update'), [
        'preferences' => ['events' => false],
    ])->assertSuccessful();

    expect($user->fresh()->notificationPreference)->not->toBeNull();
});

it('keeps the other toggles when one changes', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $this->putJson(route('notification-preferences.update'), ['preferences' => ['transit' => false]])->assertSuccessful();
    $this->putJson(route('notification-preferences.update'), ['preferences' => ['events' => false]])
        ->assertSuccessful()
        ->assertJsonPath('preferences.transit', false)
        ->assertJsonPath('preferences.events', false);

    expect($user->fresh()->wantsNotification('transit'))->toBeFalse();
});

it('drops the retired weekly digest', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    NotificationPreference::create(['user_id' => $user->id, 'preferences' => ['digest' => true, 'transit' => false]]);
    $this->actingAs($user);

    $this->getJson(route('notification-preferences.show'))->assertJsonMissingPath('preferences.digest');
    $this->putJson(route('notification-preferences.update'), ['preferences' => ['digest' => true]])->assertSuccessful();

    expect($user->fresh()->notificationPreference->preferences)->toBe(['transit' => false]);
});

it('shows quiet hours 22:00–06:00 and an automatic brief until they set their own', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $this->getJson(route('notification-preferences.show'))
        ->assertJson(['quiet_from' => '22:00', 'quiet_to' => '06:00', 'brief_at' => null]);

    // A row made by a toggle keeps the same defaults (the columns' own).
    $this->putJson(route('notification-preferences.update'), ['preferences' => ['transit' => false]]);
    $this->getJson(route('notification-preferences.show'))
        ->assertJson(['quiet_from' => '22:00', 'quiet_to' => '06:00', 'brief_at' => null]);
});

it('saves quiet hours and the brief time as times', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $this->putJson(route('notification-preferences.update'), ['quiet_from' => '23:30', 'quiet_to' => '07:00', 'brief_at' => '07:15'])
        ->assertSuccessful()
        ->assertJson(['quiet_from' => '23:30', 'quiet_to' => '07:00', 'brief_at' => '07:15']);

    $row = DB::table('notification_preferences')->where('user_id', $user->id)->first();
    expect([$row->quiet_from, $row->quiet_to, $row->brief_at])->toBe(['23:30:00', '07:00:00', '07:15:00']);

    // Back to automatic.
    $this->putJson(route('notification-preferences.update'), ['brief_at' => null])
        ->assertSuccessful()
        ->assertJsonPath('brief_at', null);
});

it('refuses quiet hours that start and end at once, or are not times', function (array $body, string $field) {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $this->putJson(route('notification-preferences.update'), $body)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($user->fresh()->notificationPreference)->toBeNull();
})->with([
    'same start and end' => [['quiet_from' => '22:00', 'quiet_to' => '22:00'], 'quiet_from'],
    'end equal to the saved start' => [['quiet_to' => '22:00'], 'quiet_from'],
    'not a time' => [['quiet_from' => '25:00', 'quiet_to' => '06:00'], 'quiet_from'],
    'seconds' => [['quiet_to' => '06:00:00'], 'quiet_to'],
    'brief not a time' => [['brief_at' => 'soon'], 'brief_at'],
]);

it('refuses a brief time inside quiet hours, across midnight too', function (string $from, string $to, string $brief, bool $refused) {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $response = $this->putJson(route('notification-preferences.update'), ['quiet_from' => $from, 'quiet_to' => $to, 'brief_at' => $brief]);

    $refused ? $response->assertUnprocessable()->assertJsonValidationErrors('brief_at') : $response->assertSuccessful();
})->with([
    'before the end of overnight quiet' => ['22:00', '06:00', '05:59', true],
    'at the end of overnight quiet' => ['22:00', '06:00', '06:00', false],
    'at the start of overnight quiet' => ['22:00', '06:00', '22:00', true],
    'midday quiet' => ['12:00', '14:00', '13:00', true],
    'after midday quiet' => ['12:00', '14:00', '07:30', false],
]);

it('wantsNotification returns correct value', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    NotificationPreference::create([
        'user_id' => $user->id,
        'preferences' => ['transit' => false, 'burgeramt' => true],
    ]);

    expect($user->wantsNotification('transit'))->toBeFalse();
    expect($user->wantsNotification('burgeramt'))->toBeTrue();
    expect($user->wantsNotification('unknown'))->toBeTrue(); // defaults to true
});

it('requires authentication', function () {
    $this->getJson(route('notification-preferences.show'))
        ->assertUnauthorized();

    $this->putJson(route('notification-preferences.update'), [
        'preferences' => ['transit' => false],
    ])->assertUnauthorized();
});
