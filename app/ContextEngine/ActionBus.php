<?php

namespace App\ContextEngine;

use App\Bureaucracy\GuidancePublication;
use App\Events\Context\ScoredActionInserted;
use App\Models\User;
use App\Services\MuteService;
use App\Support\NotificationThrottle;
use App\Support\PushDecision;
use App\Support\RedisLogger;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Per-user priority queue of pending ScoredActions backed by a Redis ZSET.
 *
 * Members are JSON-encoded ScoredActions, scored by their numeric score for ZREVRANGE.
 * Stable action_key dedups repeat insertions of the same source event.
 */
class ActionBus
{
    /** What changes how important a day is when it comes in (DayImportance's risks and Paperwork). */
    private const DAY_CHANGING = ['transit_disruption', 'transit_delay', 'weather_alert', 'bureaucracy_task'];

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
        // strip the push channel. Today card still shows so the
        // user can see "yes, the thing you muted is happening" if they
        // open the app deliberately.
        $muteSubject = app(MuteService::class)->muteSubjectFor($action->type, $action->payload);
        if ($muteSubject !== null && app(MuteService::class)->isMuted($user, $muteSubject[0], $muteSubject[1])) {
            $action = $action->withoutChannel(ScoredAction::CHANNEL_PUSH);
        }

        // Preference off, quiet hours, commute-only types and the per-user caps
        // all live in NotificationThrottle, keyed by these same action types.
        // Today's card still shows when the push is stripped.
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

        // A live risk or Paperwork alert changes how important their day is (DayImportance).
        if (in_array($action->type, self::DAY_CHANGING, true)) {
            DayImportance::forget($user->id);
        }

        // Notify listeners (push dispatcher, future digest emitters, etc.)
        event(new ScoredActionInserted($user, $action));

        return $action;
    }

    /**
     * A push its caller has claimed and must know the fate of (a leave reminder, a change of
     * plan, a "Remind me", a get-off, the day's brief): the same gate as every other push
     * (preference, mute, quiet hours, the budget, once per situation), sent now, so a failure
     * reaches the caller to give its claim back. Held: the caller tries again later. Its
     * alert-page card comes from the notification's own database channel, as before.
     *
     * @param  CarbonInterface|null  $about  when what it's about happens, for quiet hours
     */
    public function deliver(User $user, ScoredAction $action, Notification $notification, ?CarbonInterface $about = null): PushDecision
    {
        $muteSubject = app(MuteService::class)->muteSubjectFor($action->type, $action->payload);
        $decision = match (true) {
            $muteSubject !== null && app(MuteService::class)->isMuted($user, $muteSubject[0], $muteSubject[1]) => PushDecision::drop('muted'),
            $this->alreadyPushed($action) => PushDecision::drop('already_pushed'),
            default => NotificationThrottle::decide($user, $action->type, $action->severity, $about),
        };
        $log = ['type' => $action->type, 'action_key' => $action->actionKey, 'severity' => $action->severity, 'decision' => $decision->reason];
        if (! $decision->send) {
            RedisLogger::log("scored_action_push_dispatch:{$user->id}", $log + ['mode' => $decision->later ? 'held' : 'dropped']);

            return $decision;
        }

        // Throws to the caller, which gives its claim back; nothing is counted as sent.
        $user->notify($notification);
        NotificationThrottle::recordSent($user);
        $this->rememberPushed($action);
        RedisLogger::log("scored_action_push_dispatch:{$user->id}", $log + ['mode' => 'sent', 'notification' => $notification::class]);

        return $decision;
    }

    /**
     * Once per situation (an action key): pushed already at this severity or worse. Keeps the
     * marker alive while the situation lasts.
     */
    public function alreadyPushed(ScoredAction $action): bool
    {
        $last = Cache::get($this->pushedKey($action));
        if ($last === null || self::severityRank($action->severity) > (int) $last) {
            return false;
        }

        Cache::put($this->pushedKey($action), (int) $last, $this->dedupeUntil($action));

        return true;
    }

    public function rememberPushed(ScoredAction $action): void
    {
        Cache::put($this->pushedKey($action), self::severityRank($action->severity), $this->dedupeUntil($action));
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

    private function pushedKey(ScoredAction $action): string
    {
        return 'push_sent:'.$action->actionKey;
    }

    /** Until the action lapses (each re-insert extends it), never less than an hour. */
    private function dedupeUntil(ScoredAction $action): CarbonInterface
    {
        $floor = now()->addHour();
        $until = $action->validUntil ?? now()->addHours(6);

        return $until->greaterThan($floor) ? $until : $floor;
    }

    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 4,
            'major' => 3,
            'moderate' => 2,
            'minor' => 1,
            default => 0,
        };
    }
}
