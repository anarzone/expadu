<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Redis;

/**
 * Per-user mute store backing the "stop alerting me about Line 12 today"
 * feature (Roadmap #6, backend half).
 *
 * Storage: Redis string keys with TTL.
 *   mute:{user_id}:{type}:{key} → "1", expires after the requested TTL.
 *
 * - type is the ScoredAction type the mute applies to (e.g. "transit_disruption")
 * - key is a per-instance discriminator (e.g. line number, place_id)
 *   so a mute on Line 12 disruptions doesn't suppress Line 5 disruptions.
 *
 * Evaluators check isMuted() before inserting a push-eligible action.
 * The action can still land on the dashboard (silent suppression of push)
 * or be dropped entirely depending on where the check is made — see
 * ActionBus::insert() for the integration.
 */
class MuteService
{
    /** Default mute duration when caller doesn't specify. */
    public const DEFAULT_TTL_SECONDS = 24 * 3600;

    public function mute(User $user, string $type, string $key, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): void
    {
        Redis::setex($this->key($user->id, $type, $key), max(60, $ttlSeconds), '1');
    }

    public function unmute(User $user, string $type, string $key): void
    {
        Redis::del($this->key($user->id, $type, $key));
    }

    public function isMuted(User $user, string $type, string $key): bool
    {
        return (bool) Redis::exists($this->key($user->id, $type, $key));
    }

    /**
     * @return list<array{type: string, key: string, ttl_seconds: int}>
     */
    public function activeMutes(User $user): array
    {
        $client = Redis::connection()->client();
        if (defined('Redis::OPT_SCAN') && method_exists($client, 'setOption')) {
            $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_PREFIX);
        }

        $iterator = null;
        $pattern = "mute:{$user->id}:*";
        $dbPrefix = (string) config('database.redis.options.prefix', '');
        $logicalPrefix = "mute:{$user->id}:";
        $out = [];
        do {
            $batch = $client->scan($iterator, $pattern, 100);
            if ($batch === false) {
                break;
            }
            foreach ($batch as $rawKey) {
                // SCAN_PREFIX doesn't reliably strip the configured prefix from
                // returned keys across phpredis versions; do it ourselves.
                $key = (string) $rawKey;
                if ($dbPrefix !== '' && str_starts_with($key, $dbPrefix)) {
                    $key = substr($key, strlen($dbPrefix));
                }
                if (! str_starts_with($key, $logicalPrefix)) {
                    continue;
                }
                $remainder = substr($key, strlen($logicalPrefix));
                $parts = explode(':', $remainder, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $ttl = $client->ttl($rawKey);
                $out[] = [
                    'type' => $parts[0],
                    'key' => $parts[1],
                    'ttl_seconds' => (int) $ttl,
                ];
            }
        } while ($iterator > 0);

        return $out;
    }

    /**
     * Resolve a ScoredAction to the (type, key) tuple for mute lookups.
     * Returns null when the action type doesn't have a meaningful "key"
     * to discriminate by (e.g. weather, market closure are global).
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}|null
     */
    public function muteSubjectFor(string $actionType, array $payload): ?array
    {
        return match ($actionType) {
            'transit_disruption' => isset($payload['lines'][0])
                ? ['transit_disruption', mb_strtolower((string) $payload['lines'][0])]
                : null,
            'transit_delay' => isset($payload['line'])
                ? ['transit_delay', mb_strtolower((string) $payload['line'])]
                : null,
            'alternative_route', 'disruption_no_alt' => isset($payload['matched_route_id'])
                ? ['transit_disruption', 'route:'.$payload['matched_route_id']]
                : null,
            default => null,
        };
    }

    private function key(int $userId, string $type, string $key): string
    {
        return "mute:{$userId}:{$type}:{$key}";
    }
}
