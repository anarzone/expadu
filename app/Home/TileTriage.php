<?php

namespace App\Home;

use Illuminate\Support\Facades\Redis;

/**
 * Per-user, per-tile suppression for the Today "Right now" feed.
 *
 * When the user triages a tile (done / snooze / dismiss) we stamp a TTL'd Redis
 * key so the tile stays gone across reloads — TileComposer skips any active
 * entry while it builds the feed. This is deliberately distinct from
 * MuteService: a mute only strips the push channel and keeps the dashboard card
 * visible ("yes, the thing you muted is happening"), whereas triage hides the
 * card itself.
 *
 * Storage: tile_triage:{user_id}:{type}:{key} -> action, expiring after the TTL.
 */
class TileTriage
{
    public function apply(int $userId, string $type, string $key, string $action, int $ttlSeconds): void
    {
        Redis::setex($this->redisKey($userId, $type, $key), max(60, $ttlSeconds), $action);
    }

    public function isActive(int $userId, string $type, string $key): bool
    {
        return (bool) Redis::exists($this->redisKey($userId, $type, $key));
    }

    /**
     * Drop every active triage for the user — the "Restore all" escape hatch.
     * phpredis returns keys WITH the configured prefix but del() re-applies it,
     * so strip the prefix before deleting (the same gotcha the test bootstrap
     * handles for location keys).
     */
    public function clearAll(int $userId): void
    {
        $prefix = (string) config('database.redis.options.prefix', '');
        $strip = fn (string $key): string => $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;

        $keys = array_map($strip, Redis::keys("tile_triage:{$userId}:*"));
        if ($keys !== []) {
            Redis::del(...$keys);
        }
    }

    private function redisKey(int $userId, string $type, string $key): string
    {
        return "tile_triage:{$userId}:{$type}:{$key}";
    }
}
