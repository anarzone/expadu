<?php

namespace App\ContextEngine\Listeners;

use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Notifications\BuergeramtSlotNotification;
use App\Notifications\LeaveByReminderNotification;
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
 * concrete Notification dispatch. Roadmap #3.
 *
 * Two phases:
 * 1. Log-only (default): writes to scored_action_push_dispatch:{userId}
 *    Redis log, does NOT actually call notify(). Lets us verify a 48h
 *    parity window against the legacy notify() volume in the alerts
 *    table without double-pushing.
 * 2. Live (CONTEXT_ENGINE_PUSH_VIA_BUS=true): calls $user->notify(...)
 *    AND legacy notify() calls in source commands must be deleted in
 *    the same change to prevent double delivery.
 *
 * Action types without a Notification class (alternative_route,
 * disruption_no_alt, transit_disruption from synthetic events with
 * id=0) are skipped silently — they're dashboard-only.
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
            'buergeramt_slot' => $this->buildBuergeramt($action),
            'rhine_level' => $this->buildRhine($action),
            'market_closure' => $this->buildMarketClosure($action),
            'leave_by' => $this->buildLeaveBy($action),
            // alternative_route, disruption_no_alt — dashboard surface only,
            // they accompany a transit_disruption that already pushes.
            default => null,
        };
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

    private function buildBuergeramt(ScoredAction $action): ?BuergeramtSlotNotification
    {
        $officeId = $action->payload['office_id'] ?? null;
        $dates = $action->payload['dates'] ?? [];
        if ($officeId === null) {
            return null;
        }

        // The legacy notification expects an array of "office_id => slot info".
        return new BuergeramtSlotNotification([
            $officeId => [
                'name' => $officeId,
                'next_slot' => $dates[0] ?? null,
            ],
        ]);
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

    private function buildLeaveBy(ScoredAction $action): ?LeaveByReminderNotification
    {
        $placeId = $action->payload['place_id'] ?? null;
        if ($placeId === null) {
            return null;
        }

        // Legacy expects a fully-populated "data" array; the evaluator
        // doesn't currently capture mode/leave_by/weather, so we keep the
        // notification minimal. When push_via_bus goes live, we should
        // enrich the leave_by ScoredAction payload to include the same
        // fields the legacy SendLeaveByReminders command builds.
        return new LeaveByReminderNotification([
            'place_name' => 'your destination',
            'leave_by' => '',
            'mode' => 'tram',
            'mode_emoji' => '🚊',
            'place_id' => $placeId,
        ]);
    }
}
