<?php

namespace App\ContextEngine\Evaluators;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Bureaucracy\Reminders\PlanReminderDelivery;
use App\Bureaucracy\Reminders\PlanReminderReference;
use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Models\User;
use App\Models\UserTask;
use App\Privacy\ProcessingConsentStore;
use Carbon\CarbonImmutable;

final class BureaucracyEvaluator
{
    public function __construct(private ActionBus $bus, private Scorer $scorer, private AccountHolderPlan $plans,
        private PlanAttention $attention, private PlanReminderReference $references, private PlanReminderDelivery $delivery) {}

    /** The optional legacy argument is ignored; no consumer may derive guidance from UserTask. */
    public function evaluate(User $user, ?UserTask $legacy = null): ?string
    {
        $plan = $this->plans->for($user);
        foreach ($this->attention->for($plan) as $row) {
            if ($row['urgency'] === 'upcoming') {
                continue;
            }
            $severity = $row['kind'] === 'legal_due' && in_array($row['urgency'], ['overdue', 'critical'], true)
                ? 'critical' : 'moderate';
            $reference = $this->references->for($user, $row);
            $nonce = config('context_engine.push_via_bus') ? $this->delivery->reserve($reference) : null;
            $reference = [...$reference, 'reservation' => $nonce, 'mute_key' => $this->references->muteKey($user, $row)];
            $channels = [ScoredAction::CHANNEL_DASHBOARD, ScoredAction::CHANNEL_ALERT_PAGE];
            if ($nonce !== null) {
                $channels[] = ScoredAction::CHANNEL_PUSH;
            }
            $action = new ScoredAction(
                type: 'bureaucracy_task',
                actionKey: 'bureaucracy:v2:'.ProcessingConsentStore::digest([$user->id, $row['person_id'], $row['id']]),
                score: $this->scorer->score($severity, Scorer::RELEVANCE_SITUATION_MATCH, Scorer::TEMPORAL_INSIDE_WINDOW),
                severity: $severity, validUntil: CarbonImmutable::parse($plan['next_reassessment_at']),
                deliverChannels: $channels, payload: $reference, createdAt: CarbonImmutable::now(),
            );
            $accepted = false;
            try {
                $inserted = $this->bus->insert($user, $action);
                $accepted = in_array(ScoredAction::CHANNEL_PUSH, $inserted->deliverChannels, true);
            } finally {
                if ($nonce !== null && ! $accepted) {
                    $this->delivery->release($reference);
                }
            }
        }

        return $plan['next_reassessment_at'] ?? null;
    }
}
