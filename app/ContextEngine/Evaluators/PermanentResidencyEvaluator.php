<?php

namespace App\ContextEngine\Evaluators;

use App\Bureaucracy\PermanentResidencyEligibility;
use App\ContextEngine\ActionBus;
use App\ContextEngine\PushLedger;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Models\User;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use App\Support\NotificationThrottle;
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
 * The push is gated apart from the announcement (PushLedger): when the bus
 * strips it — per-user caps, quiet hours — it stays owed and a later run sends
 * just the push, instead of the milestone silently never pushing.
 *
 * Runs daily off bureaucracy:remind — same cadence and user set as the deadline
 * pass — because eligibility is purely a function of how long the permit has
 * been held.
 */
class PermanentResidencyEvaluator
{
    /** One announcement per crossed threshold within this window. */
    private const ANNOUNCE_DAYS = 180;

    public function __construct(
        private ActionBus $bus,
        private Scorer $scorer,
        private ProfileEngine $profileEngine,
        private PermanentResidencyEligibility $eligibility,
        private PushLedger $pushes,
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

        $threshold = (int) $hint['threshold_months'];
        $pushKey = "permanent_residency_pushed:{$user->id}:{$threshold}";
        $owedKey = "permanent_residency_push_owed:{$user->id}:{$threshold}";

        // Announce once per crossed threshold. Eligibility is a permanent state
        // until the user declares themselves settled, so without this the action
        // would re-emit on every daily run. A 180-day TTL lets it resurface as a
        // gentle reminder if they linger un-settled, without daily repetition.
        if (Cache::add("permanent_residency_announced:{$user->id}:{$threshold}", true, now()->addDays(self::ANNOUNCE_DAYS))) {
            $channels = [ScoredAction::CHANNEL_DASHBOARD, ScoredAction::CHANNEL_ALERT_PAGE];
        } elseif ($this->pushStillOwed($user, $pushKey, $owedKey)) {
            // Announced already, but its push was held back: retry only the
            // push. No alert_page, so the Good-news card isn't recorded twice.
            $channels = [ScoredAction::CHANNEL_DASHBOARD];
        } else {
            return;
        }

        $reservation = $this->pushes->reserve($pushKey, self::ANNOUNCE_DAYS);
        if ($reservation !== null) {
            $channels[] = ScoredAction::CHANNEL_PUSH;
            // Owed until RecordReservedPushSent marks the push key sent.
            Cache::put($owedKey, true, now()->addDays(self::ANNOUNCE_DAYS));
        } elseif (! in_array(ScoredAction::CHANNEL_ALERT_PAGE, $channels, true)) {
            return; // a push retry with nothing to push: sent, or another run holds it
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
            deliverChannels: $channels,
            payload: [
                'months_held' => $hint['months_held'],
                'threshold_months' => $hint['threshold_months'],
                'track_note' => $hint['track_note'],
            ] + ($reservation !== null ? ['push_reservation' => $reservation] : []),
            createdAt: CarbonImmutable::now(),
        );

        $stored = $this->bus->insert($user, $action);

        // The bus strips a throttled or unwanted push: hand the reservation back
        // so a later run can send it. (The old Cache::add-only gate lost it.)
        if ($reservation !== null && ! in_array(ScoredAction::CHANNEL_PUSH, $stored->deliverChannels, true)) {
            $this->pushes->release($reservation);
        }
    }

    /**
     * An announced milestone whose push hasn't gone out yet, and could go out
     * now. Users announced before pushes were tracked have no owed marker, so
     * they are never pushed twice. Checking canPush first keeps a muted or
     * capped user's card from being re-inserted every morning for nothing.
     */
    private function pushStillOwed(User $user, string $pushKey, string $owedKey): bool
    {
        if (! Cache::has($owedKey)) {
            return false;
        }

        if ($this->pushes->wasSent($pushKey)) {
            Cache::forget($owedKey);

            return false;
        }

        return NotificationThrottle::canPush($user, 'permanent_residency_eligible', 'success');
    }
}
