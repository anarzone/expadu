<?php

namespace App\Services;

use App\Models\UserEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class LocationDataRetention
{
    public const HISTORY_SECONDS = 604800;

    public function withUserLock(int $userId, \Closure $callback): mixed
    {
        return Cache::lock("location-write:{$userId}", 30)->block(5, $callback);
    }

    public function pruneHistory(string $key): int
    {
        $removed = (int) Redis::zremrangebyscore($key, '-inf', now()->timestamp - self::HISTORY_SECONDS);
        $oldest = Redis::zrange($key, 0, 0);

        if ($oldest !== []) {
            // Expire the whole set at the oldest member's deadline. If the
            // scheduled sweep stops, recent members may expire early, but no
            // position can survive longer than the published retention.
            $score = Redis::zscore($key, $oldest[0]);

            if (is_numeric($score)) {
                Redis::expireat($key, (int) $score + self::HISTORY_SECONDS);
            }
        }

        return $removed;
    }

    /** @return \Generator<int, string> */
    public function keys(string $pattern): \Generator
    {
        $cursor = null;
        $prefix = (string) config('database.redis.options.prefix');

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

    /** @return Builder<UserEvent> */
    public function passivePayloads(?int $userId = null): Builder
    {
        return UserEvent::query()
            ->whereIn('event_type', ['location_ping', 'spot_proximity'])
            ->whereNotNull('payload')
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId));
    }

    public function purgeUser(int $userId): void
    {
        Redis::del("location_history:{$userId}", "confirmed_location:{$userId}", "veedel_anchor:{$userId}");

        foreach ($this->keys("location_proximity:{$userId}:*") as $key) {
            Redis::del($key);
        }

        $this->passivePayloads($userId)->update(['payload' => null]);
    }
}
