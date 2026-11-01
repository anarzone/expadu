<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * Deleting the account also removes what no foreign key cascades
 * (App\Privacy\ForgetAccount): notifications, push devices, every session,
 * and the person's Redis and cache keys. Another account's stay.
 */

/** Gives an account a notification, a push device, two sessions and keys. */
function leftoversFor(User $user): void
{
    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'Test', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $user->updatePushSubscription("https://push.example/{$user->id}", 'key', 'token');
    foreach (['phone', 'laptop'] as $device) {
        DB::table('sessions')->insert(['id' => "{$device}-{$user->id}", 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    }
    Redis::set("mute:{$user->id}:line:16", '1');
    Redis::rpush("pending_actions:{$user->id}", 'x');
    Redis::set("location_history:{$user->id}", 'x');
    Cache::put("composer:plan:{$user->id}", 'x', 600);
    Cache::put("composer:today:{$user->id}", 'x', 600);
}

// Only the kinds of key this test writes (Redis::keys adds the prefix; del
// adds it back, so it's stripped first).
afterEach(function () {
    $prefix = (string) config('database.redis.options.prefix');
    foreach (['mute:*', 'pending_actions:*', 'location_history:*'] as $pattern) {
        foreach (Redis::keys($pattern) as $key) {
            Redis::del(substr($key, strlen($prefix)));
        }
    }
});

test('🔒 deleting the account removes its notifications, push devices, every session and its keys; another account’s stay', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    leftoversFor($user);
    leftoversFor($other);

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('login'));

    expect(User::find($user->id))->toBeNull()
        ->and(DB::table('notifications')->where('notifiable_id', $user->id)->count())->toBe(0)
        ->and(DB::table('push_subscriptions')->where('subscribable_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(Redis::exists("mute:{$user->id}:line:16"))->toBe(0)
        ->and(Redis::exists("pending_actions:{$user->id}"))->toBe(0)
        ->and(Redis::exists("location_history:{$user->id}"))->toBe(0)
        ->and(Cache::has("composer:plan:{$user->id}"))->toBeFalse()
        ->and(Cache::has("composer:today:{$user->id}"))->toBeFalse();

    expect(DB::table('notifications')->where('notifiable_id', $other->id)->count())->toBe(1)
        ->and(DB::table('push_subscriptions')->where('subscribable_id', $other->id)->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(2)
        ->and(Redis::exists("mute:{$other->id}:line:16"))->toBe(1)
        ->and(Cache::has("composer:plan:{$other->id}"))->toBeTrue();
});
