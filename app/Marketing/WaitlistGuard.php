<?php

namespace App\Marketing;

use App\Security\FormTraps;
use App\Security\MailBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Keeps scripts from using the waitlist to mail strangers. In November 2026
 * a bot posted hundreds of other people's addresses, and every post sent a
 * real confirmation mail from us. FormTraps catches scripts without
 * bothering people, and one mail per address per day caps what any leak can
 * send.
 *
 * A caught post gets the same reply as a real one, so the script learns
 * nothing, but nothing is saved and no mail goes out.
 */
final class WaitlistGuard
{
    private const MailsTodayKey = 'waitlist:mails-today';

    public const Trap = FormTraps::Trap;

    public const Stamp = FormTraps::Stamp;

    public function __construct(private readonly FormTraps $traps, private readonly MailBudget $budget) {}

    /**
     * Confirmation mails the waitlist may send in a day, all addresses
     * together: its share of the email budget (config/mail_budget.php), so
     * it can never use up what account mails need. Real signups run at a
     * few a day.
     */
    public static function dailyMailCap(): int
    {
        return (int) config('mail_budget.kinds.waitlist.daily');
    }

    /** When the form was drawn, sealed so it can't be forged or reused later. */
    public static function stamp(): string
    {
        return FormTraps::stamp();
    }

    /**
     * Why this post looks scripted, or null when it looks like a person.
     *
     * @return 'trap'|'no_stamp'|'too_fast'|null
     */
    public function suspicion(Request $request): ?string
    {
        return $this->traps->suspicion($request);
    }

    /** True when the form was drawn so long ago it should be reloaded first. */
    public function expired(Request $request): bool
    {
        return $this->traps->expired($request);
    }

    /**
     * Whether this address may get a confirmation mail now: one per day.
     * Claims the day's mail when it may.
     */
    public function claimMail(string $email): bool
    {
        if (! Cache::add('waitlist:mailed:'.sha1($email), 1, now()->addDay())) {
            return false;
        }

        RateLimiter::hit(self::MailsTodayKey, 86_400);

        return true;
    }

    /**
     * Whether another confirmation mail fits today: the waitlist's claims so
     * far (counted as they're queued) and the day's whole email budget.
     */
    public function mailsLeftToday(): bool
    {
        return ! RateLimiter::tooManyAttempts(self::MailsTodayKey, self::dailyMailCap())
            && $this->budget->hasRoom('waitlist');
    }
}
