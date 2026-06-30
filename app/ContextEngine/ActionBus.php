<?php

namespace App\ContextEngine;

use App\Events\Context\ScoredActionInserted;
use App\Models\User;
use App\Services\MuteService;
use App\Support\NotificationThrottle;
use App\Support\RedisLogger;
use Illuminate\Support\Facades\Redis;

/**
 * Per-user priority queue of pending ScoredActions backed by a Redis ZSET.
 *
 * Members are JSON-encoded ScoredActions, scored by their numeric score for ZREVRANGE.
 * Stable action_key dedups repeat insertions of the same source event.
 */
class ActionBus
{
    /**
     * Insert (or update) a scored action for a user. Throttled push channels
     * are stripped here so downstream delivery does not need to re-check.
     */
    /**
     * Map ScoredAction types to notification-preference keys (the same
     * keys NotificationPreference::defaults() exposes). Roadmap #4.
     *
     * If a user has wantsNotification($key) === false, the push channel
     * is stripped before the action lands in the bus — dashboard card
     * still shows but no push is sent. This works regardless of whether
     * push delivery flows through the legacy notify() path or the
     * future dispatcher path.
     */
    private const TYPE_TO_PREF_KEY = [
        'transit_disruption' => 'transit',
        'transit_delay' => 'transit',
        'weather_alert' => 'weather',
        'rhine_level' => 'rhine',
        'buergeramt_slot' => 'burgeramt',
        'bureaucracy_task' => 'checklist',
        'permanent_residency_eligible' => 'checklist',
        'market_closure' => 'events',
    ];

    public function insert(User $user, ScoredAction $action): ScoredAction
    {
        // Per-user mute (Roadmap #6 backend) — when a user has muted a
        // specific instance (e.g. Line 12 disruptions for the day), we
        // strip the push channel. Dashboard card still shows so the
        // user can see "yes, the thing you muted is happening" if they
        // open the app deliberately.
        $muteSubject = app(MuteService::class)->muteSubjectFor($action->type, $action->payload);
        if ($muteSubject !== null && app(MuteService::class)->isMuted($user, $muteSubject[0], $muteSubject[1])) {
            $action = $action->withoutChannel(ScoredAction::CHANNEL_PUSH);
        }

        if (in_array(ScoredAction::CHANNEL_PUSH, $action->deliverChannels, true)) {
            $prefKey = self::TYPE_TO_PREF_KEY[$action->type] ?? null;
            if ($prefKey !== null && method_exists($user, 'wantsNotification') && ! $user->wantsNotification($prefKey)) {
                $action = $action->withoutChannel(ScoredAction::CHANNEL_PUSH);
            } elseif (! NotificationThrottle::canPush($user, $action->type)) {
                $action = $action->withoutChannel(ScoredAction::CHANNEL_PUSH);
            }
        }

        $key = $this->key($user->id);

        // Remove any prior member with the same action_key, then re-add with current score.
        $this->removeByActionKey($user->id, $action->actionKey);

        Redis::zadd($key, $action->score, json_encode($action, JSON_THROW_ON_ERROR));

        // Keep ZSET TTL ~7 days; sweeper trims expired members on read.
        Redis::expire($key, 7 * 24 * 3600);

        // Persistent 30-day insert log so we can review activity after
        // valid_until expiries clear the live ZSET. Mirrors the existing
        // commute_context / leaveby_debug streams.
        RedisLogger::log("scored_action:{$user->id}", [
            'type' => $action->type,
            'action_key' => $action->actionKey,
            'score' => $action->score,
            'severity' => $action->severity,
            'channels' => $action->deliverChannels,
            'valid_until' => $action->validUntil?->toIso8601String(),
        ]);

        // Notify listeners (push dispatcher, future digest emitters, etc.)
        event(new ScoredActionInserted($user, $action));

        return $action;
    }

    /**
     * Read top-K actions, oldest expired members swept on the way out.
     *
     * @return list<ScoredAction>
     */
    public function topK(int $userId, int $k = 20): array
    {
        $raw = Redis::zrevrange($this->key($userId), 0, $k - 1);
        if (empty($raw)) {
            return [];
        }

        $actions = [];
        $expired = [];

        foreach ($raw as $member) {
            $data = json_decode((string) $member, true);
            if (! is_array($data)) {
                $expired[] = $member;

                continue;
            }
            $action = ScoredAction::fromArray($data);
            if ($action->isExpired()) {
                $expired[] = $member;

                continue;
            }
            $actions[] = $action;
        }

        if ($expired) {
            Redis::zrem($this->key($userId), ...$expired);
        }

        return $actions;
    }

    public function remove(int $userId, string $actionKey): void
    {
        $this->removeByActionKey($userId, $actionKey);
    }

    public function clear(int $userId): void
    {
        Redis::del($this->key($userId));
    }

    private function removeByActionKey(int $userId, string $actionKey): void
    {
        $key = $this->key($userId);
        $members = Redis::zrange($key, 0, -1);
        foreach ($members as $member) {
            $data = json_decode((string) $member, true);
            if (is_array($data) && ($data['action_key'] ?? null) === $actionKey) {
                Redis::zrem($key, $member);
            }
        }
    }

    private function key(int $userId): string
    {
        return "pending_actions:{$userId}";
    }
}
