<?php

use App\Models\User;
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

test('mute writes a Redis key that isMuted detects', function () {
    $user = User::factory()->create();
    $mute = app(MuteService::class);

    expect($mute->isMuted($user, 'transit_disruption', '12'))->toBeFalse();

    $mute->mute($user, 'transit_disruption', '12', 3600);

    expect($mute->isMuted($user, 'transit_disruption', '12'))->toBeTrue();
});

test('unmute removes the entry', function () {
    $user = User::factory()->create();
    $mute = app(MuteService::class);

    $mute->mute($user, 'transit_disruption', '12');
    $mute->unmute($user, 'transit_disruption', '12');

    expect($mute->isMuted($user, 'transit_disruption', '12'))->toBeFalse();
});

test('mutes are scoped per user, type, and key', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $mute = app(MuteService::class);

    $mute->mute($userA, 'transit_disruption', '12');

    expect($mute->isMuted($userA, 'transit_disruption', '12'))->toBeTrue();
    expect($mute->isMuted($userA, 'transit_disruption', '15'))->toBeFalse();
    expect($mute->isMuted($userA, 'transit_delay', '12'))->toBeFalse();
    expect($mute->isMuted($userB, 'transit_disruption', '12'))->toBeFalse();
});

test('activeMutes lists current entries', function () {
    $user = User::factory()->create();
    $mute = app(MuteService::class);

    $mute->mute($user, 'transit_disruption', '12');
    $mute->mute($user, 'transit_delay', 'S1');

    $list = $mute->activeMutes($user);
    expect($list)->toHaveCount(2);

    $types = array_column($list, 'type');
    expect($types)->toContain('transit_disruption');
    expect($types)->toContain('transit_delay');
});

test('muteSubjectFor extracts (type, key) from action payload', function () {
    $mute = app(MuteService::class);

    expect($mute->muteSubjectFor('transit_disruption', ['lines' => ['12', '15']]))
        ->toBe(['transit_disruption', '12']);

    expect($mute->muteSubjectFor('transit_delay', ['line' => 'S1']))
        ->toBe(['transit_delay', 's1']);

    expect($mute->muteSubjectFor('alternative_route', ['matched_route_id' => 5]))
        ->toBe(['transit_disruption', 'route:5']);

    expect($mute->muteSubjectFor('weather_alert', []))->toBeNull();
});
