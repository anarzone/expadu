<?php

namespace App\Bureaucracy;

use App\Profile\Profile;
use Carbon\Carbon;

/**
 * Niederlassungserlaubnis (permanent-residency) eligibility check. Pure date
 * math over the resolved Profile: when a non-EU resident has held their permit
 * past their track's threshold, they may apply. The remaining statutory
 * conditions (pension months, B1 German, secured livelihood) are listed in the
 * track note for the user to confirm — never asserted, since Cologne's counters
 * only mention NE if you ask.
 *
 * Single source of truth shared by the bureaucracy page hint
 * (BureaucracyController::index) and the good-news producer
 * (PermanentResidencyEvaluator), so the two never drift.
 */
class PermanentResidencyEligibility
{
    /**
     * @return array{months_held: int, threshold_months: int, track_note: string}|null
     *                                                                                 null when the user is EU, has not recorded a permit-held date, has
     *                                                                                 already declared themselves settled, or has not yet crossed the bar.
     */
    public function for(Profile $profile): ?array
    {
        // Already declared settled (and typically already holding PR) — don't
        // nudge them toward qualifying for what they have.
        if (($profile->attributes['settled_at'] ?? null) !== null) {
            return null;
        }

        $heldSince = $profile->attributes['permit_held_since'] ?? null;
        if ($heldSince === null || $profile->isEu) {
            return null;
        }

        [$threshold, $trackNote] = $this->trackThreshold($profile);

        $monthsHeld = (int) now()->startOfDay()->diffInMonths(Carbon::parse($heldSince), true);

        if ($monthsHeld < $threshold) {
            return null;
        }

        return [
            'months_held' => $monthsHeld,
            'threshold_months' => $threshold,
            'track_note' => $trackNote,
        ];
    }

    /**
     * The track-specific NE threshold (months) + the note we surface so the
     * user can confirm the conditions date math alone cannot prove.
     *
     * @return array{0: int, 1: string}
     */
    private function trackThreshold(Profile $profile): array
    {
        return match (true) {
            ($profile->attributes['permit_track'] ?? null) === 'blue_card' => [21, 'Blue Card holders qualify after 21 months with B1 German (27 with A1).'],
            ($profile->attributes['sponsor'] ?? null) === 'german' && ($profile->attributes['purpose'] ?? null) === 'family' => [36, 'Family members of German citizens qualify after 3 years (§28 Abs. 2).'],
            ($profile->attributes['purpose'] ?? null) === 'employment' => [36, 'Skilled workers qualify after 3 years — just 2 with a German degree (§18c).'],
            default => [60, 'The general route opens after 5 years (§9).'],
        };
    }
}
