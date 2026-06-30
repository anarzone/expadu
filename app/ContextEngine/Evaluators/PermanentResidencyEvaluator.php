<?php

namespace App\ContextEngine\Evaluators;

use App\Bureaucracy\PermanentResidencyEligibility;
use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Models\User;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The one *positive* ContextEngine producer — it feeds the v4 Alerts "Good
 * news" lane. When a resident crosses their Niederlassungserlaubnis
 * (permanent-residency) threshold, that milestone is emitted as a
 * success-severity ScoredAction; AlertClassifier then routes success → the
 * `good` lane.
 *
 * Unlike the deadline evaluators this is a milestone, not a recurring nudge:
 * the whole emission is gated by a first-crossing check so eligibility is
 * announced once per threshold rather than re-carded every morning the
 * scheduler runs. (The deadline evaluators only gate *push* and re-emit the
 * dashboard card daily, which suits a countdown but would make a "you qualify"
 * milestone feel broken.)
 *
 * Runs daily off bureaucracy:remind — same cadence and user set as the deadline
 * pass — because eligibility is purely a function of how long the permit has
 * been held.
 */
class PermanentResidencyEvaluator
{
    public function __construct(
        private ActionBus $bus,
        private Scorer $scorer,
        private ProfileEngine $profileEngine,
        private PermanentResidencyEligibility $eligibility,
    ) {}

    /**
     * Emit the good-news action if the user is newly eligible. The caller may
     * pass an already-built Profile (the remind command has one) — otherwise we
     * resolve it; ProfileEngine::build is pure, so the rebuild is cheap.
     */
    public function evaluate(User $user, ?Profile $profile = null): void
    {
        $profile ??= $this->profileEngine->build($user);

        $hint = $this->eligibility->for($profile);
        if ($hint === null) {
            return;
        }

        // Announce once per crossed threshold. Eligibility is a permanent state
        // until the user declares themselves settled, so without this the action
        // would re-emit on every daily run. A 180-day TTL lets it resurface as a
        // gentle reminder if they linger un-settled, without daily repetition.
        if (! $this->firstCrossing($user->id, $hint['threshold_months'])) {
            return;
        }

        $score = $this->scorer->score(
            severity: 'success',
            personalRelevance: Scorer::RELEVANCE_SITUATION_MATCH,
            temporalRelevance: Scorer::TEMPORAL_INSIDE_WINDOW,
        );

        $action = new ScoredAction(
            type: 'permanent_residency_eligible',
            actionKey: "permanent_residency:{$hint['threshold_months']}:user:{$user->id}",
            score: $score,
            severity: 'success',
            validUntil: CarbonImmutable::now()->addDays(30),
            deliverChannels: [
                ScoredAction::CHANNEL_DASHBOARD,
                ScoredAction::CHANNEL_ALERT_PAGE,
                ScoredAction::CHANNEL_PUSH,
            ],
            payload: [
                'months_held' => $hint['months_held'],
                'threshold_months' => $hint['threshold_months'],
                'track_note' => $hint['track_note'],
            ],
            createdAt: CarbonImmutable::now(),
        );

        $this->bus->insert($user, $action);
    }

    /**
     * Atomic first-crossing check: true the first time this (user, threshold)
     * pair is announced, false thereafter for 180 days.
     */
    private function firstCrossing(int $userId, int $thresholdMonths): bool
    {
        return Cache::add("permanent_residency_announced:{$userId}:{$thresholdMonths}", true, now()->addDays(180));
    }
}
