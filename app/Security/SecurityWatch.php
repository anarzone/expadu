<?php

namespace App\Security;

use App\Bureaucracy\Verification\Escalations;
use App\Mail\SecurityAlert;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WaitlistSignup;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Reads the security log for the admins: the numbers behind the daily
 * summary, and the hourly marks (config/security_watch.php) that alert when
 * something looks wrong. Each rule alerts once a day, by email to the admins
 * and through the escalations (the error reporter), so a flood can't become
 * a flood of alerts.
 */
final class SecurityWatch
{
    public function __construct(private readonly Escalations $escalations, private readonly MailBudget $budget) {}

    /**
     * What the security log holds since a moment.
     *
     * @return array{signups: array<string, int>, logins: int, failed_logins: int, throttled: int, blocked: array<string, int>, blocked_domains: array<string, int>, resets: int, verified: int, mail_held: int, waitlist_confirmed: int}
     */
    public function since(CarbonInterface $since): array
    {
        $events = SecurityEvent::query()->where('created_at', '>=', $since);
        $count = fn (string ...$types): int => (clone $events)->whereIn('type', $types)->count();
        $grouped = fn (string $type, string $column): array => (clone $events)->where('type', $type)->whereNotNull($column)
            ->selectRaw("{$column} as name, count(*) as n")->groupBy($column)->orderByDesc('n')->limit(5)
            ->pluck('n', 'name')->map(fn ($n): int => (int) $n)->all();

        return [
            'signups' => $grouped(SecurityLog::Signup, 'channel'),
            'logins' => $count(SecurityLog::Login),
            'failed_logins' => $count(SecurityLog::LoginFailed, SecurityLog::TwoFactorFailed),
            'throttled' => $count(SecurityLog::Throttled),
            'blocked' => $grouped(SecurityLog::Blocked, 'reason'),
            'blocked_domains' => $grouped(SecurityLog::Blocked, 'email_domain'),
            'resets' => $count(SecurityLog::ResetRequested),
            'verified' => $count(SecurityLog::EmailVerified),
            'mail_held' => $count(SecurityLog::MailHeld),
            'waitlist_confirmed' => WaitlistSignup::query()->where('confirmed_at', '>=', $since)->count(),
        ];
    }

    /**
     * The hourly marks reached now, and the day's email budget once mostly used.
     *
     * @return array<string, array{count: int, mark: int, summary: string}>
     */
    public function breaches(): array
    {
        $hour = $this->since(now()->subHour());
        $counts = [
            'signups' => array_sum($hour['signups']),
            'blocked' => array_sum($hour['blocked']),
            'failed_logins' => $hour['failed_logins'],
            'throttled' => $hour['throttled'],
        ];
        $words = [
            'signups' => 'sign-ups',
            'blocked' => 'posts turned away by the bot traps',
            'failed_logins' => 'failed sign-ins',
            'throttled' => 'sign-in tries refused by the rate limits',
        ];

        $breaches = [];
        foreach ($counts as $rule => $count) {
            $mark = (int) config("security_watch.hourly.{$rule}");
            if ($mark > 0 && $count >= $mark) {
                $breaches[$rule] = ['count' => $count, 'mark' => $mark,
                    'summary' => "{$count} {$words[$rule]} in the last hour (the alert mark is {$mark})."];
            }
        }

        $daily = (int) config('mail_budget.daily');
        $mark = (int) ceil($daily * (float) config('security_watch.mail_used'));
        $sent = $this->budget->sent();
        if ($daily > 0 && $sent >= $mark) {
            $breaches['mail_used'] = ['count' => $sent, 'mark' => $mark,
                'summary' => "{$sent} of today’s {$daily} emails are used. Account emails stop when none are left."];
        }

        return $breaches;
    }

    /**
     * Alerts each breach not yet alerted today.
     *
     * @return list<string> the rules alerted now
     */
    public function check(): array
    {
        $alerted = [];
        foreach ($this->breaches() as $rule => $breach) {
            $day = now('UTC')->toDateString();
            if (! Cache::add("security-watch:alerted:{$rule}:{$day}", true, now()->addDays(2))) {
                continue;
            }

            $this->escalations->raise("security_{$rule}", $day, Escalations::High, $breach['summary']);
            foreach (self::admins() as $email) {
                Mail::to($email)->send(new SecurityAlert($rule, $breach['summary']));
            }
            $alerted[] = $rule;
        }

        return $alerted;
    }

    /** @return list<string> the admins' verified addresses */
    public static function admins(): array
    {
        return User::query()->where('is_admin', true)->whereNotNull('email_verified_at')->pluck('email')->all();
    }
}
