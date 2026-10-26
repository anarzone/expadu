<?php

namespace App\Privacy;

use App\Models\ProcessingOptIn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Processing a person turns on in Settings and keeps on until they turn it off (owner, 26 Oct
 * 2026: AI-written day briefs, opt-in, off by default). The yes is bound to the notice and the
 * provider they saw: when either changes it lapses until they say yes again. Each use still gets
 * its own request permission through ProcessingConsentStore, so the gate's checks, the audit
 * row and a withdrawal's reach are the same as for a message they send.
 */
final class ProcessingOptIns
{
    /** Only processing made to run without a request of theirs may be turned on for good. */
    public const STANDING = [ProcessingPurpose::NotificationBrief, ProcessingPurpose::TodayRank];

    public function __construct(private ProcessingConsentStore $consents) {}

    /** On, under the notice and provider as they are now. */
    public function active(User $user, ProcessingPurpose $purpose): bool
    {
        $row = $this->row($user, $purpose);

        return $row !== null && $row->withdrawn_at === null && $purpose->available()
            && $row->notice_version === config('bureaucracy_privacy.notice_version')
            && $row->provider_version === $purpose->providerVersion();
    }

    /**
     * Their yes to the notice they were shown: refused when it isn't the current one.
     *
     * @throws ValidationException
     */
    public function turnOn(User $user, ProcessingPurpose $purpose, mixed $noticeVersion, mixed $providerVersion): void
    {
        if (! in_array($purpose, self::STANDING, true) || ! $purpose->available()
            || $noticeVersion !== config('bureaucracy_privacy.notice_version') || $providerVersion !== $purpose->providerVersion()) {
            throw ValidationException::withMessages(['processing' => 'Please review the current notice before turning this on.']);
        }
        ProcessingOptIn::query()->updateOrCreate(['user_id' => $user->id, 'purpose' => $purpose->value], [
            'notice_version' => $noticeVersion, 'provider_version' => $providerVersion, 'granted_at' => now()->utc(), 'withdrawn_at' => null,
        ]);
    }

    /** Off, at once: what's in flight is withdrawn too, and its result never used (ProcessingConsentStore::withdraw). */
    public function turnOff(User $user, ProcessingPurpose $purpose): int
    {
        return DB::transaction(function () use ($user, $purpose): int {
            ProcessingOptIn::query()->where('user_id', $user->id)->where('purpose', $purpose->value)->whereNull('withdrawn_at')
                ->update(['withdrawn_at' => now()->utc()]);

            return $this->consents->withdraw($user, purpose: $purpose);
        });
    }

    /**
     * One use's own permission under their standing yes, bound to what it sends (`$context`), or
     * none when it's off or has lapsed.
     *
     * @param  array<string, mixed>  $context
     */
    public function permit(User $user, ProcessingPurpose $purpose, array $context): ?ProcessingPermit
    {
        if (! in_array($purpose, self::STANDING, true) || ! $this->active($user, $purpose)) {
            return null;
        }
        $row = $this->row($user, $purpose);

        return $this->consents->grant($user, $purpose, $context, ['consent' => true, 'request_id' => (string) Str::uuid(),
            'notice_version' => $row->notice_version, 'provider_version' => $row->provider_version]);
    }

    private function row(User $user, ProcessingPurpose $purpose): ?ProcessingOptIn
    {
        return ProcessingOptIn::query()->where('user_id', $user->id)->where('purpose', $purpose->value)->first();
    }
}
