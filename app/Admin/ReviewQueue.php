<?php

namespace App\Admin;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WaitlistSignup;
use App\Security\DisposableEmail;
use App\Security\SecurityLog;
use App\Security\SecurityStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Needs a look": accounts and waitlist entries the checks flagged and the
 * admin hasn't kept yet. An account: three or more sign-ups from its IP
 * within a day, never verified after a day, or a throwaway inbox. A waitlist
 * entry: confirmed within seconds (a mail scanner), never confirmed and from
 * before the clean-up began (the owner's to decide), or a throwaway inbox.
 */
final class ReviewQueue
{
    public const SameIpSignups = 3;

    public function __construct(private readonly DisposableEmail $disposable) {}

    /**
     * @return list<array{key: string, kind: 'account'|'waitlist', id: int, title: string, flags: list<array{label: string, color: string}>, why: string, evidence: string}>
     */
    public function items(): array
    {
        return $this->accounts()->concat($this->waitlist())
            ->sortByDesc('at')->values()->map(fn (array $item): array => collect($item)->except('at')->all())->all();
    }

    public function count(): int
    {
        return count($this->items());
    }

    /** @return Collection<int, array<string, mixed>> */
    private function accounts(): Collection
    {
        $users = User::query()->where('is_admin', false)->whereNull('suspended_at')->whereNull('reviewed_at')
            ->where(fn ($q) => $q->whereNull('email_verified_at')->orWhere('created_at', '>=', now()->subDays(30)))
            ->latest()->limit(200)->get();
        $signups = SecurityEvent::query()->where('type', SecurityLog::Signup)->whereIn('user_id', $users->pluck('id'))
            ->get(['user_id', 'ip', 'channel', 'reason', 'created_at'])->keyBy('user_id');

        return $users->map(function (User $user) use ($signups): ?array {
            $signup = $signups->get($user->id);
            $joined = CarbonImmutable::parse($user->created_at);
            $flags = [];
            $why = [];
            $others = 0;

            if ($signup?->ip !== null) {
                $others = SecurityEvent::query()->where('type', SecurityLog::Signup)->where('ip', $signup->ip)
                    ->whereBetween('created_at', [$joined->subDay(), $joined->addDay()])->count();
                if ($others >= self::SameIpSignups) {
                    $flags[] = ['label' => "Same IP ×{$others}", 'color' => 'warning'];
                    $why[] = "{$others} sign-ups from one address within a day.";
                }
            }
            if ($user->email_verified_at === null && $joined->lt(now()->subDay())) {
                $days = (int) $joined->diffInDays(now());
                $flags[] = ['label' => "Unverified {$days}d", 'color' => 'warning'];
                $why[] = $user->verification_reminded_at !== null
                    ? 'Never verified. Reminder sent '.$user->verification_reminded_at->format('j M').'; deleted after '.$user->verification_reminded_at->addDays((int) config('cleanup.accounts.reminder_days_before'))->format('j M').' unless verified.'
                    : 'Never verified. A reminder goes out on day '.((int) config('cleanup.accounts.unverified_days') - (int) config('cleanup.accounts.reminder_days_before')).'.';
            }
            if ($this->disposable->isDisposable((string) $user->email)) {
                $flags[] = ['label' => 'Throwaway inbox', 'color' => 'danger'];
                $why[] = 'The address is a throwaway inbox.';
            }
            if ($flags === []) {
                return null;
            }

            $from = SecurityStats::Channels[$signup?->channel ?? ''] ?? 'Website';

            return [
                'key' => "account:{$user->id}", 'kind' => 'account', 'id' => (int) $user->id, 'at' => $joined,
                'title' => trim($user->name.' · '.$user->email, ' ·'), 'flags' => $flags, 'why' => implode(' ', $why),
                'evidence' => implode(' · ', array_filter([$from, $joined->format('j M'), $signup?->ip,
                    $others > 1 ? 'also '.($others - 1).' other'.($others > 2 ? 's' : '').' from this address' : null])),
            ];
        })->filter()->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function waitlist(): Collection
    {
        $from = CarbonImmutable::parse((string) config('cleanup.waitlist.from'));

        return WaitlistSignup::query()->whereNull('reviewed_at')->latest()->limit(200)->get()
            ->map(function (WaitlistSignup $signup) use ($from): ?array {
                $at = CarbonImmutable::parse($signup->created_at);
                $flags = [];
                $why = [];
                if ($signup->confirmed_at !== null) {
                    $seconds = (int) $at->diffInSeconds(CarbonImmutable::parse($signup->confirmed_at), true);
                    if ($seconds < SecurityStats::ScannerSeconds) {
                        $flags[] = ['label' => 'Scanner?', 'color' => 'danger'];
                        $why[] = "Confirmed {$seconds} s after signing up: a mail scanner, not a person.";
                    }
                } elseif ($at->lt($from)) {
                    $flags[] = ['label' => 'Never confirmed', 'color' => 'warning'];
                    $why[] = 'From before the automatic clean-up began, so it stays until you decide.';
                }
                if ($this->disposable->isDisposable((string) $signup->email)) {
                    $flags[] = ['label' => 'Throwaway inbox', 'color' => 'danger'];
                    $why[] = 'The address is a throwaway inbox.';
                }
                if ($flags === []) {
                    return null;
                }

                return [
                    'key' => "waitlist:{$signup->id}", 'kind' => 'waitlist', 'id' => (int) $signup->id, 'at' => $at,
                    'title' => (string) $signup->email, 'flags' => $flags, 'why' => implode(' ', $why),
                    'evidence' => implode(' · ', array_filter([$signup->source, $at->format('j M'), $signup->city])),
                ];
            })->filter()->values();
    }
}
