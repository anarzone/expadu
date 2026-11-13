<?php

namespace App\Console\Commands\Security;

use App\Mail\SecuritySummary;
use App\Models\BureaucracyEscalation;
use App\Security\MailBudget;
use App\Security\SecurityWatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Each morning: the last 24 hours of the security log, emailed to the admins. */
class SendSecuritySummary extends Command
{
    protected $signature = 'security:daily-summary';

    protected $description = 'Email the admins the last 24 hours of sign-ups, sign-ins and blocked posts';

    public function handle(SecurityWatch $watch, MailBudget $budget): int
    {
        $admins = SecurityWatch::admins();
        if ($admins === []) {
            $this->warn('No admin with a verified email; nothing sent.');

            return self::SUCCESS;
        }

        $since = now()->subDay();
        // The watch's alerts and the email budget's (MailBudget).
        $alerts = BureaucracyEscalation::query()->where(fn ($q) => $q->where('kind', 'like', 'security\_%')->orWhere('kind', 'like', 'mail\_%'))
            ->where('last_seen_at', '>=', $since)
            ->orderBy('last_seen_at')->pluck('summary')->all();
        $mail = ['yesterday' => $budget->sent(day: now()->subDay()), 'today' => $budget->sent(), 'daily' => (int) config('mail_budget.daily')];

        foreach ($admins as $email) {
            Mail::to($email)->send(new SecuritySummary($watch->since($since), $mail, $alerts));
        }
        $this->info('Sent to '.count($admins).' admin(s).');

        return self::SUCCESS;
    }
}
