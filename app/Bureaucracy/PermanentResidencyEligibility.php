<?php

namespace App\Bureaucracy;

use App\Profile\Profile;

/**
 * Compatibility adapter during the reviewed-assessor cutover.
 * A Profile does not establish the complete statutory criteria. Personalised
 * options come from the source-checked case plan, never this duration calculator.
 */
class PermanentResidencyEligibility
{
    /**
     * @return array{months_held: int, threshold_months: int, track_note: string}|null
     *                                                                                 null until the caller supplies a reviewed assessment, not just a Profile.
     */
    public function for(Profile $profile): ?array
    {
        return null;
    }

    /**
     * The NE tracks with their statutory thresholds — the single source for
     * both the in-app eligibility check and the public marketing tool, so the
     * two can never disagree on a legal figure.
     *
     * @return array<string, array{months: int, label: string, note: string}>
     */
    public static function tracks(): array
    {
        return [
            'blue_card' => [
                'months' => 21,
                'label' => 'EU Blue Card',
                'note' => 'Blue Card holders qualify after 21 months with B1 German (27 with A1).',
            ],
            'family_of_german' => [
                'months' => 36,
                'label' => 'Family member of a German citizen',
                'note' => 'Family members of German citizens qualify after 3 years (§28 Abs. 2).',
            ],
            'skilled_worker' => [
                'months' => 36,
                'label' => 'Skilled worker (employment permit)',
                'note' => 'Skilled workers qualify after 3 years — just 2 with a German degree (§18c).',
            ],
            'general' => [
                'months' => 60,
                'label' => 'General route',
                'note' => 'The general route opens after 5 years (§9).',
            ],
        ];
    }
}
