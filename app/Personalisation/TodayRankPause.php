<?php

namespace App\Personalisation;

use App\Models\User;
use App\Models\UserSetting;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use Carbon\CarbonImmutable;

/**
 * Today's AI order waiting for the person to agree again (owner, 29 Oct 2026, the consent
 * canvas's boards 9–11): its notice or the AI service changed since their yes, so Today keeps the
 * order everyone sees until they agree. Settings always says so; Today says so unless they chose
 * Not now in the last week. What was learned is kept either way.
 */
final class TodayRankPause
{
    /** How long Not now keeps it off Today. */
    public const LATER_DAYS = 7;

    /** The user setting Not now is kept under: until when, as an ISO time. */
    private const LATER = 'today_rank_later_until';

    public function __construct(private ProcessingOptIns $optIns) {}

    /**
     * For Settings: the notice now and what changed (ProcessingOptIns::paused), or null when
     * Today's AI order isn't waiting on them.
     *
     * @return array{notice: list<string>, changes: list<string>|null, model_changed: bool, notice_version: string, provider_version: string}|null
     */
    public function for(User $user): ?array
    {
        if (! PersonalisationWording::covers($user, 2)) {
            return null;
        }

        return $this->optIns->paused($user, ProcessingPurpose::TodayRank);
    }

    /**
     * For Today: the same, unless they chose Not now in the last week.
     *
     * @return array{notice: list<string>, changes: list<string>|null, model_changed: bool, notice_version: string, provider_version: string}|null
     */
    public function onToday(User $user): ?array
    {
        $until = $user->setting(self::LATER);
        if (is_string($until) && rescue(fn () => CarbonImmutable::parse($until)->isFuture(), false, false)) {
            return null;
        }

        return $this->for($user);
    }

    /** Not now: off Today for LATER_DAYS (until when); Settings still says so. */
    public function later(User $user): string
    {
        $until = now()->addDays(self::LATER_DAYS)->toIso8601String();
        $this->keep($user, $until);

        return $until;
    }

    /** They agreed: nothing left to put off. */
    public function agreed(User $user): void
    {
        $this->keep($user, null);
    }

    private function keep(User $user, ?string $until): void
    {
        // As saved, not as this request first read them.
        $settings = $user->userSetting()->first()?->settings ?? UserSetting::defaults();
        if ($until === null) {
            unset($settings[self::LATER]);
        } else {
            $settings[self::LATER] = $until;
        }
        $user->userSetting()->updateOrCreate(['user_id' => $user->id], ['settings' => $settings]);
        $user->unsetRelation('userSetting');
    }
}
