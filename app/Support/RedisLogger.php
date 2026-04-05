<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

/**
 * Lightweight Redis-based logging for debugging during testing phase.
 * All keys use sorted sets scored by timestamp, with 30-day TTL.
 */
class RedisLogger
{
    private const TTL = 2592000; // 30 days

    /**
     * Log an entry to a Redis sorted set.
     *
     * @param  array<string, mixed>  $data
     */
    public static function log(string $key, array $data): void
    {
        try {
            $timestamp = now()->timestamp;
            $data['at'] = now()->toIso8601String();

            Redis::zadd($key, $timestamp, json_encode($data));
            Redis::zremrangebyscore($key, 0, now()->subMonth()->timestamp);
            Redis::expire($key, self::TTL);
        } catch (\Throwable) {
            // Redis unavailable — skip silently
        }
    }
}
