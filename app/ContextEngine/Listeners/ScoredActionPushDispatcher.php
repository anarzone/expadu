<?php

namespace App\ContextEngine\Listeners;

use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Notifications\BureaucracyDeadlineNotification;
use App\Notifications\MarketClosureNotification;
use App\Notifications\RhineFloodNotification;
use App\Notifications\TransitDelayNotification;
use App\Notifications\TransitDisruptionNotification;
use App\Notifications\WeatherAlertNotification;
use App\Support\NotificationThrottle;
use App\Support\RedisLogger;
use Illuminate\Notifications\Notification;
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
    public function handle(ScoredActionInserted $event): void
    {
        $action = $event->action;
        $user = $event->user;

        if (! in_array('push', $action->deliverChannels, true)) {
            return;
        }

        $notification = $this->buildNotification($action);
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

    private function buildNotification(ScoredAction $action): ?Notification
    {
        return match ($action->type) {
            'transit_disruption' => $this->buildTransitDisruption($action),
            'transit_delay' => $this->buildTransitDelay($action),
            'weather_alert' => $this->buildWeatherAlert($action),
            'rhine_level' => $this->buildRhine($action),
            'market_closure' => $this->buildMarketClosure($action),
            'bureaucracy_task' => $this->buildBureaucracyTask($action),
            // alternative_route, disruption_no_alt — dashboard surface only,
            // they accompany a transit_disruption that already pushes.
            default => null,
        };
    }

    private function buildBureaucracyTask(ScoredAction $action): ?BureaucracyDeadlineNotification
    {
        $title = (string) ($action->payload['title'] ?? '');
        if ($title === '') {
            return null;
        }

        return new BureaucracyDeadlineNotification(
            taskTitle: $title,
            tier: (string) ($action->payload['tier'] ?? 'urgent'),
            daysRemaining: (int) ($action->payload['days_remaining'] ?? 0),
            deadline: (string) ($action->payload['deadline'] ?? ''),
            taskId: isset($action->payload['task_id']) ? (int) $action->payload['task_id'] : null,
        );
    }

    private function buildTransitDisruption(ScoredAction $action): ?TransitDisruptionNotification
    {
        $lines = $action->payload['lines'] ?? [];
        if (empty($lines)) {
            return null;
        }

        return new TransitDisruptionNotification([
            'line' => implode(', ', array_slice($lines, 0, 3)),
            'summary' => $this->disruptionSummary($action),
        ]);
    }

    private function disruptionSummary(ScoredAction $action): string
    {
        $lines = $action->payload['lines'] ?? [];
        $line = $lines[0] ?? 'transit';

        return "Line {$line} disrupted on your route";
    }

    private function buildTransitDelay(ScoredAction $action): ?TransitDelayNotification
    {
        $line = $action->payload['line'] ?? null;
        $delay = (int) ($action->payload['delay_min'] ?? 0);
        $stop = $action->payload['stop_id'] ?? '';
        if ($line === null || $delay <= 0) {
            return null;
        }

        return new TransitDelayNotification($line, $delay, $stop);
    }

    private function buildWeatherAlert(ScoredAction $action): ?WeatherAlertNotification
    {
        $alert = $action->payload['alert'] ?? [];
        $title = $alert['title'] ?? '';
        $description = $alert['description'] ?? '';
        if ($title === '') {
            return null;
        }

        return new WeatherAlertNotification($title, $description);
    }

    private function buildRhine(ScoredAction $action): ?RhineFloodNotification
    {
        $level = (float) ($action->payload['level'] ?? 0);
        if ($level <= 0) {
            return null;
        }

        // Legacy expects centimetres + status + trend; we only have meters + threshold.
        return new RhineFloodNotification(
            levelCm: $level * 100.0,
            status: (string) ($action->payload['threshold'] ?? 'warning'),
            trend: 'rising',
        );
    }

    private function buildMarketClosure(ScoredAction $action): ?MarketClosureNotification
    {
        $reason = (string) ($action->payload['reason'] ?? '');
        if ($reason === '') {
            return null;
        }

        return new MarketClosureNotification($reason, null);
    }
}
