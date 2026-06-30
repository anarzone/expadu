<?php

namespace App\ContextEngine\Listeners;

use App\ContextEngine\ContextNotificationFactory;
use App\Events\Context\ScoredActionInserted;
use App\Support\NotificationThrottle;
use App\Support\RedisLogger;
use Illuminate\Support\Facades\Log;

/**
 * Translates a ScoredAction with the `push` delivery channel into a
 * concrete Notification dispatch. This is the ONLY push delivery path —
 * the legacy notify() calls in the Check* commands were removed when
 * CONTEXT_ENGINE_PUSH_VIA_BUS went live (v2 cutover).
 *
 * With push_via_bus=false the dispatcher logs to
 * scored_action_push_dispatch:{userId} instead of sending — useful as a
 * kill switch if push delivery misbehaves.
 *
 * Action types without a Notification class (alternative_route,
 * disruption_no_alt) are skipped silently — they're dashboard-only.
 */
class ScoredActionPushDispatcher
{
    public function __construct(private ContextNotificationFactory $notifications) {}

    public function handle(ScoredActionInserted $event): void
    {
        $action = $event->action;
        $user = $event->user;

        if (! in_array('push', $action->deliverChannels, true)) {
            return;
        }

        $notification = $this->notifications->build($action);
        if ($notification === null) {
            return; // dashboard-only action type
        }

        $context = [
            'user_id' => $user->id,
            'action_key' => $action->actionKey,
            'action_type' => $action->type,
            'severity' => $action->severity,
            'score' => $action->score,
        ];

        if (! config('context_engine.push_via_bus')) {
            // Phase 1: log-only. Verify volume parity against legacy.
            RedisLogger::log("scored_action_push_dispatch:{$user->id}", $context + [
                'mode' => 'log_only',
                'would_send' => $notification::class,
            ]);

            return;
        }

        // Phase 2: real delivery. ActionBus already stripped the push
        // channel if NotificationThrottle::canPush returned false at
        // insert time, so we don't re-check here — just record-sent.
        try {
            $user->notify($notification);
            NotificationThrottle::recordSent($user);
            RedisLogger::log("scored_action_push_dispatch:{$user->id}", $context + [
                'mode' => 'sent',
                'notification' => $notification::class,
            ]);
        } catch (\Throwable $e) {
            Log::error('ScoredActionPushDispatcher failed to send', $context + [
                'error' => $e->getMessage(),
            ]);
            RedisLogger::log("scored_action_push_dispatch:{$user->id}", $context + [
                'mode' => 'failed',
                'error' => substr($e->getMessage(), 0, 200),
            ]);
        }
    }
}
