<?php

use App\Models\User;
use App\Models\UserEvent;
use App\Services\EventTrackingService;
use Illuminate\Support\Facades\Redis;

test('location pings arriving after opt-out do not store coordinates or derived events', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['share_location' => false]]);

    $this->actingAs($user)->postJson('/api/track', [
        'event_type' => 'location_ping',
        'payload' => ['lat' => 50.94, 'lng' => 6.94, 'accuracy' => 10, 'quality' => 'high'],
    ])->assertNoContent();

    expect(UserEvent::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Redis::exists("location_history:{$user->id}"))->toBe(0)
        ->and(Redis::exists("veedel_anchor:{$user->id}"))->toBe(0);
});

test('location tracking checks current settings rather than an earlier loaded relation', function () {
    $user = User::factory()->onboarded()->create();
    $setting = $user->userSetting()->create(['settings' => ['share_location' => true]]);
    $user->load('userSetting');
    $setting->update(['settings' => ['share_location' => false]]);

    app(EventTrackingService::class)->track($user, 'location_ping', [
        'lat' => 50.94, 'lng' => 6.94, 'accuracy' => 10,
    ]);

    expect(UserEvent::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Redis::exists("location_history:{$user->id}"))->toBe(0);
});

test('location sharing can be enabled again without restoring old pings', function () {
    $user = User::factory()->onboarded()->create();
    $setting = $user->userSetting()->create(['settings' => ['share_location' => false]]);
    $tracking = app(EventTrackingService::class);
    $payload = ['lat' => 50.94, 'lng' => 6.94, 'accuracy' => 300, 'rejected' => 'accuracy_300m'];

    $tracking->track($user, 'location_ping', $payload);
    expect(UserEvent::where('user_id', $user->id)->count())->toBe(0);
    $setting->update(['settings' => ['share_location' => true]]);
    $tracking->track($user, 'location_ping', $payload);

    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'location_ping')->count())->toBe(1)
        ->and(Redis::zcard("location_history:{$user->id}"))->toBe(1);
});

test('location opt-out does not prevent non-location events', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['share_location' => false]]);

    $this->actingAs($user)->postJson('/api/track', [
        'event_type' => 'page_viewed', 'payload' => ['page' => '/travel'],
    ])->assertNoContent();

    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'page_viewed')->count())->toBe(1);
});
