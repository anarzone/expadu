<?php

namespace App\Security;

use App\Bureaucracy\Verification\Escalations;
use Illuminate\Support\Facades\Cache;

/**
 * Every email's pass (config/mail_budget.php): its kind's share of the day,
 * the day's total and its address's share. Counts are per UTC day, the
 * provider's day. Holding a mail, or passing the warning mark, alerts once
 * a day through the escalations (the error reporter).
 */
final class MailBudget
{
    public function __construct(private readonly Escalations $escalations) {}

    /**
     * The kind of a mail, from the class the mailer names in its data.
     *
     * @param  array<string, mixed>  $data
     */
    public function kindOf(array $data): string
    {
        $class = $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? null;

        foreach (config('mail_budget.kinds') as $kind => $rules) {
            if (is_string($class) && in_array($class, $rules['classes'] ?? [], true)) {
                return $kind;
            }
        }

        return 'other';
    }

    /** Whether a mail of this kind still fits today (for forms that queue mail). */
    public function hasRoom(string $kind): bool
    {
        $share = config("mail_budget.kinds.{$kind}.daily");

        return $this->count('total') < (int) config('mail_budget.daily')
            && ($share === null || $this->count($kind) < (int) $share);
    }

    /**
     * Admits a mail and counts it, or says why it's held.
     *
     * @param  array<int, string>  $recipients
     * @return 'daily_total'|'kind_share'|'per_address'|null
     */
    public function admit(string $kind, array $recipients): ?string
    {
        $total = (int) config('mail_budget.daily');
        $share = config("mail_budget.kinds.{$kind}.daily");
        $perAddress = config("mail_budget.kinds.{$kind}.per_address");

        $held = match (true) {
            $this->count('total') >= $total => 'daily_total',
            $share !== null && $this->count($kind) >= (int) $share => 'kind_share',
            $perAddress !== null && collect($recipients)->contains(fn (string $to): bool => $this->count($this->addressKey($kind, $to)) >= (int) $perAddress) => 'per_address',
            default => null,
        };

        if ($held !== null) {
            // An address at its share is a person or script asking again; only
            // running out of the day's mail is worth waking someone for.
            if ($held !== 'per_address') {
                $this->alert("mail_held_{$kind}", "Email budget: {$kind} mail is being held ({$held}). Today: {$this->count('total')}/{$total} sent.");
            }

            return $held;
        }

        $sent = $this->increment('total');
        $this->increment($kind);
        foreach ($recipients as $to) {
            $this->increment($this->addressKey($kind, $to));
        }

        if ($sent === (int) ceil($total * (float) config('mail_budget.warn_at'))) {
            $this->alert('mail_budget_warning', "Email budget: {$sent} of today’s {$total} emails are used.");
        }

        return null;
    }

    private function alert(string $kind, string $summary): void
    {
        $this->escalations->raise($kind, now('UTC')->toDateString(), Escalations::High, $summary);
    }

    private function addressKey(string $kind, string $address): string
    {
        return $kind.':'.sha1(mb_strtolower(trim($address)));
    }

    private function count(string $name): int
    {
        return (int) Cache::get($this->key($name), 0);
    }

    private function increment(string $name): int
    {
        Cache::add($this->key($name), 0, now()->addDays(2));

        return (int) Cache::increment($this->key($name));
    }

    private function key(string $name): string
    {
        return 'mail-budget:'.now('UTC')->toDateString().':'.$name;
    }
}
