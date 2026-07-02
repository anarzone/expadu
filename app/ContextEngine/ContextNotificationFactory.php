<?php

namespace App\ContextEngine;

use App\Notifications\BureaucracyDeadlineNotification;
use App\Notifications\MarketClosureNotification;
use App\Notifications\PermanentResidencyEligibleNotification;
use App\Notifications\RhineFloodNotification;
use App\Notifications\TransitDelayNotification;
use App\Notifications\TransitDisruptionNotification;
use App\Notifications\WeatherAlertNotification;
use Illuminate\Notifications\Notification;

/**
 * Builds the concrete Notification for a ScoredAction. Shared by the push
 * dispatcher (which sends it as a web push) and the alert-center recorder
 * (which reads its toArray() for the in-app card title/body/link) — one place
 * formats both surfaces, so the push and the alert never drift apart.
 *
 * Returns null for action types with no notification (alternative_route,
 * disruption_no_alt — dashboard-only companions) or an empty payload.
 */
class ContextNotificationFactory
{
    public function build(ScoredAction $action): ?Notification
    {
        return match ($action->type) {
            'transit_disruption' => $this->buildTransitDisruption($action),
            'transit_delay' => $this->buildTransitDelay($action),
            'weather_alert' => $this->buildWeatherAlert($action),
            'rhine_level' => $this->buildRhine($action),
            'market_closure' => $this->buildMarketClosure($action),
            'bureaucracy_task' => $this->buildBureaucracyTask($action),
            'permanent_residency_eligible' => $this->buildPermanentResidency($action),
            default => null,
        };
    }

    private function buildPermanentResidency(ScoredAction $action): PermanentResidencyEligibleNotification
    {
        return new PermanentResidencyEligibleNotification(
            monthsHeld: (int) ($action->payload['months_held'] ?? 0),
            thresholdMonths: (int) ($action->payload['threshold_months'] ?? 0),
            trackNote: (string) ($action->payload['track_note'] ?? ''),
        );
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
            appointmentAt: isset($action->payload['appointment_at']) ? (string) $action->payload['appointment_at'] : null,
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
