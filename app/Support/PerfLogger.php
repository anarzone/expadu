<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Lightweight perf telemetry: per-key Redis sorted set of timing entries.
 * Used by PerfTrackMiddleware for routes and by service classes wrapping
 * external API calls. Read via `php artisan perf:report`.
 */
class PerfLogger
{
    private const TTL = 604800;

    private const KEY_PREFIX = 'perf:';

    /** Every recorded key, so keys() never has to scan Redis with KEYS. */
    private const INDEX_KEY = 'perf:index';

    /**
     * Time a callable, record outcome, return its value.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  array<string, mixed>  $tags
     * @return T
     */
    public static function measure(string $key, callable $fn, array $tags = []): mixed
    {
        $start = microtime(true);
        $ok = true;
        try {
            return $fn();
        } catch (Throwable $e) {
            $ok = false;
            throw $e;
        } finally {
            self::record($key, [
                'ms' => (int) round((microtime(true) - $start) * 1000),
                'ok' => $ok ? 1 : 0,
                ...$tags,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data  must include 'ms' integer
     */
    public static function record(string $key, array $data): void
    {
        // Sampled (config app.perf_sample_rate): percentiles hold, counts are
        // a fraction of real traffic.
        $rate = (float) config('app.perf_sample_rate', 1.0);
        if ($rate < 1.0 && mt_rand() / mt_getrandmax() >= $rate) {
            return;
        }

        try {
            $now = now()->timestamp;
            $data['at'] = $now;
            $fullKey = self::KEY_PREFIX.$key;

            // One round trip instead of one per command.
            Redis::pipeline(function ($pipe) use ($fullKey, $key, $now, $data) {
                $pipe->zadd($fullKey, $now, (string) json_encode($data));
                $pipe->zremrangebyscore($fullKey, 0, $now - self::TTL);
                $pipe->expire($fullKey, self::TTL);
                $pipe->sadd(self::INDEX_KEY, $key);
                $pipe->expire(self::INDEX_KEY, self::TTL);
            });
        } catch (Throwable) {
            // Redis unavailable — telemetry is best-effort, never break the request
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function entries(string $key, int $sinceTs, ?int $untilTs = null): array
    {
        try {
            $raw = Redis::zrangebyscore(self::KEY_PREFIX.$key, $sinceTs, $untilTs ?? '+inf');

            return array_values(array_filter(array_map(
                fn ($m) => is_array($d = json_decode((string) $m, true)) ? $d : null,
                (array) $raw
            )));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * All known telemetry keys (without the perf: prefix). Keys whose entries
     * have all expired are dropped from the index on the way out.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        try {
            $keys = array_values(array_map('strval', (array) Redis::smembers(self::INDEX_KEY)));
            $gone = array_values(array_filter($keys, fn (string $key) => ! Redis::exists(self::KEY_PREFIX.$key)));

            if ($gone !== []) {
                Redis::srem(self::INDEX_KEY, ...$gone);
            }

            return array_values(array_diff($keys, $gone));
        } catch (Throwable) {
            return [];
        }
    }
}
