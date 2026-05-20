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
        try {
            $now = now()->timestamp;
            $data['at'] = $now;
            $fullKey = self::KEY_PREFIX.$key;
            Redis::zadd($fullKey, $now, (string) json_encode($data));
            Redis::zremrangebyscore($fullKey, 0, $now - self::TTL);
            Redis::expire($fullKey, self::TTL);
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
     * All known telemetry keys (without the perf: prefix).
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        try {
            $raw = (array) Redis::keys(self::KEY_PREFIX.'*');
            $configPrefix = (string) config('database.redis.options.prefix', '');

            return array_values(array_map(function ($k) use ($configPrefix) {
                $k = (string) $k;
                if ($configPrefix !== '' && str_starts_with($k, $configPrefix)) {
                    $k = substr($k, strlen($configPrefix));
                }

                return str_starts_with($k, self::KEY_PREFIX) ? substr($k, strlen(self::KEY_PREFIX)) : $k;
            }, $raw));
        } catch (Throwable) {
            return [];
        }
    }
}
