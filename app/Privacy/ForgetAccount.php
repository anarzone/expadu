<?php

namespace App\Privacy;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * What deleting an account must also remove that no foreign key cascades
 * (privacy review, 26 Oct 2026): its notifications and push devices (both
 * polymorphic), its sessions on every device, and the person's keys in
 * Redis and the cache (mutes, pending actions, Composer's private plan
 * copies). Location keys go through LocationDataRetention::purgeUser.
 */
final class ForgetAccount
{
    public function forget(User $user): void
    {
        $user->notifications()->delete();
        $user->pushSubscriptions()->delete();
        DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

        Redis::del("pending_actions:{$user->id}");
        foreach ($this->redisKeys("mute:{$user->id}:*") as $key) {
            Redis::del($key);
        }

        foreach (['plan', 'today', 'handoff'] as $purpose) {
            Cache::forget("composer:{$purpose}:{$user->id}");
        }
        Cache::forget("composer:today:{$user->id}:hidden");
    }

    /**
     * Keys matching a pattern, without the connection's prefix (Redis::del
     * adds it back).
     *
     * @return iterable<string>
     */
    private function redisKeys(string $pattern): iterable
    {
        $prefix = (string) config('database.redis.options.prefix');
        $cursor = null;

        do {
            $page = Redis::scan($cursor, ['match' => $prefix.$pattern, 'count' => 100]);
            if ($page === false) {
                break;
            }
            [$cursor, $keys] = $page;
            foreach ($keys as $key) {
                yield str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
            }
        } while ((int) $cursor !== 0);
    }
}
