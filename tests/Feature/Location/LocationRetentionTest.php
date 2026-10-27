<?php

use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\EventTrackingService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

test('passive location tracking keeps coordinates and place identities out of durable analytics', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['share_location' => true]]);
    Spot::factory()->create(['lat' => 50.94, 'lng' => 6.94]);

    app(EventTrackingService::class)->track($user, 'location_ping', [
        'lat' => 50.94, 'lng' => 6.94, 'accuracy' => 10, 'quality' => 'high',
    ]);

    $events = UserEvent::where('user_id', $user->id)->get();
    expect($events->pluck('event_type')->all())->toContain('location_ping', 'spot_proximity');
    foreach ($events as $event) {
        expect($event->payload)->toBeNull();
    }
    expect(Redis::zcard("location_history:{$user->id}"))->toBe(1);
    app(EventTrackingService::class)->track($user, 'location_ping', ['lat' => 50.94, 'lng' => 6.94, 'accuracy' => 10]);
    expect(Redis::zcard("location_history:{$user->id}"))->toBe(1)
        ->and(UserEvent::where('user_id', $user->id)->where('event_type', 'spot_proximity')->count())->toBe(1);
});

test('an in-flight location change cannot bypass the lock used by opt-out and account deletion', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['share_location' => true]]);
    $lock = Cache::lock("location-write:{$user->id}", 30);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => app(EventTrackingService::class)->track($user, 'location_ping', ['lat' => 50.94, 'lng' => 6.94]))
            ->toThrow(LockTimeoutException::class);
        expect(Redis::exists("location_history:{$user->id}"))->toBe(0)
            ->and(UserEvent::where('user_id', $user->id)->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

test('a new ping cannot extend the oldest retained location beyond seven days', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['share_location' => true]]);
    $key = "location_history:{$user->id}";
    // In seconds, not calendar days: across a clock change a day isn't 86,400 s.
    Redis::zadd($key, now()->subSeconds(8 * 86400)->timestamp, 'expired');
    Redis::zadd($key, now()->subSeconds(6 * 86400)->timestamp, 'recent');
    Redis::expire($key, 2592000);

    app(EventTrackingService::class)->track($user, 'location_ping', [
        'lat' => 50.94, 'lng' => 6.94, 'rejected' => 'accuracy',
    ]);

    expect(Redis::zrange($key, 0, -1))->not->toContain('expired')->toContain('recent')
        ->and((int) Redis::ttl($key))->toBeGreaterThan(86390)->toBeLessThanOrEqual(86400);
});

test('retention cleanup reports a dry run then removes expired members and redacts passive database payloads', function () {
    $user = User::factory()->onboarded()->create();
    // This deliberately has no corresponding user: the sweep must cover orphaned keys too.
    $key = 'location_history:987654321';
    Redis::zadd($key, now()->subSeconds(7 * 86400)->timestamp, 'boundary');
    Redis::zadd($key, now()->subSeconds(7 * 86400 - 300)->timestamp, 'within-window');
    Redis::expire($key, 2592000);
    $old = UserEvent::factory()->for($user)->create([
        'event_type' => 'location_ping', 'payload' => ['lat' => 50.94, 'lng' => 6.94],
        'created_at' => now()->subDays(8),
    ]);
    $recent = UserEvent::factory()->for($user)->create([
        'event_type' => 'spot_proximity', 'payload' => ['spot_id' => 42, 'spot_name' => 'Private place', 'lat' => 50.94],
    ]);
    $ordinary = UserEvent::factory()->for($user)->create(['event_type' => 'page_viewed', 'payload' => ['page' => 'today']]);

    $this->artisan('location:prune-history --dry-run')->assertSuccessful();
    expect(Redis::zcard($key))->toBe(2)->and($old->fresh()->payload)->not->toBeNull();
    $this->artisan('location:prune-history')->assertSuccessful();
    expect(Redis::zrange($key, 0, -1))->toBe(['within-window'])
        ->and((int) Redis::ttl($key))->toBeGreaterThan(290)->toBeLessThanOrEqual(300)
        ->and($old->fresh()->payload)->toBeNull()
        ->and($recent->fresh()->payload)->toBeNull()
        ->and($ordinary->fresh()->payload)->toBe(['page' => 'today']);
});

test('turning off location sharing removes previously retained positions and anchors', function () {
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['share_location' => true]]);
    Redis::zadd("location_history:{$user->id}", now()->timestamp, 'position');
    Redis::setex("confirmed_location:{$user->id}", 600, 'position');
    Redis::setex("veedel_anchor:{$user->id}", 600, 'position');
    Redis::setex("location_proximity:{$user->id}:42", 600, '1');
    $legacy = UserEvent::factory()->for($user)->create(['event_type' => 'location_ping', 'payload' => ['lat' => 50.94]]);

    $this->actingAs($user)->putJson(route('user-settings.update'), ['settings' => ['share_location' => false]])->assertSuccessful();

    expect(Redis::exists("location_history:{$user->id}"))->toBe(0)
        ->and(Redis::exists("confirmed_location:{$user->id}"))->toBe(0)
        ->and(Redis::exists("veedel_anchor:{$user->id}"))->toBe(0)
        ->and(Redis::exists("location_proximity:{$user->id}:42"))->toBe(0)
        ->and($legacy->fresh()->payload)->toBeNull();
});

test('deleting an account removes its retained positions without touching another account', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    Redis::zadd("location_history:{$user->id}", now()->timestamp, 'position');
    Redis::setex("confirmed_location:{$user->id}", 600, 'position');
    Redis::setex("veedel_anchor:{$user->id}", 600, 'position');
    Redis::zadd("location_history:{$other->id}", now()->timestamp, 'other position');

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect(route('login'));

    expect($user->fresh())->toBeNull()
        ->and(Redis::exists("location_history:{$user->id}"))->toBe(0)
        ->and(Redis::exists("confirmed_location:{$user->id}"))->toBe(0)
        ->and(Redis::exists("veedel_anchor:{$user->id}"))->toBe(0)
        ->and(Redis::zcard("location_history:{$other->id}"))->toBe(1);
});
