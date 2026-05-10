<?php

use App\Models\User;
use App\Models\UserEvent;
use App\Services\MuteService;
use Illuminate\Support\Facades\Redis;

uses()->group('mute');

beforeEach(function () {
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'mute:*'
    );
});

test('POST /mutes redirects back for Inertia (not 204)', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->withHeaders(['X-Inertia' => 'true', 'Referer' => '/dashboard'])
        ->post(route('mutes.store'), [
            'type' => 'transit_disruption',
            'key' => 'all',
            'duration_seconds' => 3600,
        ]);

    $response->assertRedirect();
    expect(app(MuteService::class)->isMuted($user, 'transit_disruption', 'all'))->toBeTrue();
});

test('POST /mutes returns JSON when wantsJson', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->postJson(route('mutes.store'), [
        'type' => 'weather_alert',
        'key' => 'all',
        'duration_seconds' => 3600,
    ])->assertOk()->assertJson(['ok' => true]);
});

test('POST /mutes/thumbs-down redirects back for Inertia', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->withHeaders(['X-Inertia' => 'true', 'Referer' => '/dashboard'])
        ->post(route('mutes.thumbs-down'), [
            'action_key' => 'alert:42',
            'card_type' => 'transit_disruption',
        ]);

    $response->assertRedirect();
    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'card_dismissed')->count())->toBe(1);
});

test('DELETE /mutes redirects back', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);
    app(MuteService::class)->mute($user, 'transit_disruption', 'all', 3600);

    $response = $this->withHeaders(['X-Inertia' => 'true', 'Referer' => '/dashboard'])
        ->delete(route('mutes.destroy'), [
            'type' => 'transit_disruption',
            'key' => 'all',
        ]);

    $response->assertRedirect();
    expect(app(MuteService::class)->isMuted($user, 'transit_disruption', 'all'))->toBeFalse();
});
