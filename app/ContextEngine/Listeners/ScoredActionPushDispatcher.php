<?php

namespace App\ContextEngine\Listeners;

use App\Bureaucracy\Reminders\PlanReminderDelivery;
use App\ContextEngine\ActionBus;
use App\ContextEngine\ContextNotificationFactory;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Notifications\BureaucracyPlanNotification;
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
 *
 * One push per situation: an action key that already pushed pushes again only
 * when its severity rose, the way Alert::record re-surfaces a card only on
 * escalation. Evaluators re-insert live situations on every poll, so without
 * this a long delay re-pushed every half hour.
 */
class ScoredActionPushDispatcher
{
    public function __construct(private ContextNotificationFactory $notifications, private ActionBus $bus) {}

    public function handle(ScoredActionInserted $event): void
    {
        $action = $event->action;
        $user = $event->user;

        if (! in_array('push', $action->deliverChannels, true)) {
            return;
        }

        $notification = $this->notifications->build($action);
        if ($notification === null) {
            if ($action->type === 'bureaucracy_task') {
                app(PlanReminderDelivery::class)->release($action->payload);
            }

            return; // dashboard-only action type
        }

        $context = [
            'user_id' => $user->id,
            'action_key' => $action->actionKey,
            'action_type' => $action->type,
            'severity' => $action->severity,
            'score' => $action->score,
        ];

        // Paperwork reminders are deduped by PlanReminderDelivery, so they skip
        // this check.
        $dedupe = $action->type !== 'bureaucracy_task';
        if ($dedupe && $this->bus->alreadyPushed($action)) {
            RedisLogger::log("scored_action_push_dispatch:{$user->id}", $context + ['mode' => 'deduped']);

            return;
        }

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
            // A paperwork reminder records its own send once it clears shouldSend().
            if (! $notification instanceof BureaucracyPlanNotification) {
                NotificationThrottle::recordSent($user);
            }
            if ($dedupe) {
                $this->bus->rememberPushed($action);
            }
            RedisLogger::log("scored_action_push_dispatch:{$user->id}", $context + [
                'mode' => 'sent',
                'notification' => $notification::class,
            ]);
        } catch (\Throwable $e) {
            if ($notification instanceof BureaucracyPlanNotification) {
                app(PlanReminderDelivery::class)->release($notification->reference);
                // Let the durable reassessment retry; admitting a queue job is not delivery.
                // Its failure record keeps the exception class, not private provider text.
                throw $e;
            }
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
