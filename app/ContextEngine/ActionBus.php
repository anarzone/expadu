<?php

namespace App\ContextEngine;

use App\Bureaucracy\GuidancePublication;
use App\Events\Context\ScoredActionInserted;
use App\Models\User;
use App\Services\MuteService;
use App\Support\NotificationThrottle;
use App\Support\RedisLogger;
use Carbon\Exceptions\InvalidFormatException;
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
    public function insert(User $user, ScoredAction $action): ScoredAction
    {
        if (! app(GuidancePublication::class)->allowsAction($action, $user->id)) {
            return $action->withoutChannel(ScoredAction::CHANNEL_PUSH)
                ->withoutChannel(ScoredAction::CHANNEL_DASHBOARD)
                ->withoutChannel(ScoredAction::CHANNEL_ALERT_PAGE);
        }

        // Per-user mute (Roadmap #6 backend) — when a user has muted a
        // specific instance (e.g. Line 12 disruptions for the day), we
        // strip the push channel. Dashboard card still shows so the
        // user can see "yes, the thing you muted is happening" if they
        // open the app deliberately.
        $muteSubject = app(MuteService::class)->muteSubjectFor($action->type, $action->payload);
        if ($muteSubject !== null && app(MuteService::class)->isMuted($user, $muteSubject[0], $muteSubject[1])) {
            $action = $action->withoutChannel(ScoredAction::CHANNEL_PUSH);
        }

        // Preference off, quiet hours, commute-only types and the per-user caps
        // all live in NotificationThrottle, keyed by these same action types.
        // The dashboard card still shows when the push is stripped.
        if (in_array(ScoredAction::CHANNEL_PUSH, $action->deliverChannels, true)
            && ! NotificationThrottle::canPush($user, $action->type, $action->severity)) {
            $action = $action->withoutChannel(ScoredAction::CHANNEL_PUSH);
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
            if (! is_array($data) || ! $this->validPayload($data)) {
                $expired[] = $member;

                continue;
            }
            try {
                $action = ScoredAction::fromArray($data);
            } catch (InvalidFormatException) {
                $expired[] = $member;

                continue;
            }
            if ($action->isExpired() || ! app(GuidancePublication::class)->allowsAction($action, $userId)) {
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

    public function removeTypes(int $userId, array $types): void
    {
        foreach (Redis::zrange($this->key($userId), 0, -1) as $member) {
            $data = json_decode((string) $member, true);
            if (is_array($data) && in_array($data['type'] ?? null, $types, true)) {
                Redis::zrem($this->key($userId), $member);
            }
        }
    }

    private function validPayload(array $data): bool
    {
        foreach (['type', 'action_key', 'severity', 'created_at'] as $key) {
            if (! is_string($data[$key] ?? null) || $data[$key] === '') {
                return false;
            }
        }

        return (is_int($data['score'] ?? null) || is_float($data['score'] ?? null)) && is_finite((float) $data['score'])
            && is_array($data['payload'] ?? null) && is_array($data['deliver_channels'] ?? null)
            && array_is_list($data['deliver_channels'])
            && count(array_filter($data['deliver_channels'], fn ($channel) => ! is_string($channel)
                || ! in_array($channel, [ScoredAction::CHANNEL_DASHBOARD, ScoredAction::CHANNEL_PUSH, ScoredAction::CHANNEL_ALERT_PAGE], true))) === 0
            && (! isset($data['valid_until']) || is_string($data['valid_until']));
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
