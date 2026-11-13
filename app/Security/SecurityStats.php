<?php

namespace App\Security;

use App\Models\BureaucracyEscalation;
use App\Models\ComposerTrace;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WaitlistSignup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the admin's Sign-ups & security page (A, the watch
 * board) and the IP panel of its log (B). Read-only.
 */
final class SecurityStats
{
    /** The page's periods, as its filter offers them. */
    public const Periods = ['today' => 'Today', '7d' => '7 days', '30d' => '30 days'];

    /** Why a post was turned away, in words (SecurityLog's reasons). */
    public const Reasons = [
        'trap' => 'Hidden field filled in',
        'too_fast' => 'Sent within seconds',
        'no_stamp' => 'No form stamp',
        'disposable' => 'Throwaway inbox',
        'mail_cap' => 'Daily email cap',
    ];

    /** Where an event happened, in words (SecurityLog's channels). */
    public const Channels = ['web' => 'Website', 'app' => 'iPhone', 'admin' => 'Admin', 'waitlist' => 'Waitlist', 'mail' => 'Email'];

    /** A waitlist confirmation this soon after the mail went out was no person reading it. */
    public const ScannerSeconds = 30;

    public function __construct(private readonly MailBudget $budget) {}

    /** A browser string, short: "Chrome 124 · Linux", "Headless Chrome 124 · Linux", "Expadu app · iOS". */
    public static function browser(?string $agent): string
    {
        $agent = (string) $agent;
        if ($agent === '') {
            return '–';
        }

        $version = fn (string $name): string => preg_match('#'.$name.'/(\d+)#', $agent, $m) === 1 ? ' '.$m[1] : '';
        $browser = match (true) {
            str_contains($agent, 'Expadu') || str_contains($agent, 'CFNetwork') => 'Expadu app',
            str_contains($agent, 'HeadlessChrome') => 'Headless Chrome'.$version('HeadlessChrome'),
            str_contains($agent, 'Edg/') => 'Edge'.$version('Edg'),
            str_contains($agent, 'Firefox/') => 'Firefox'.$version('Firefox'),
            str_contains($agent, 'Chrome/') => 'Chrome'.$version('Chrome'),
            str_contains($agent, 'Safari/') => 'Safari'.$version('Version'),
            default => strtok($agent, ' /') ?: 'Other',
        };
        $os = match (true) {
            str_contains($agent, 'iPhone') || str_contains($agent, 'iOS') || str_contains($agent, 'Darwin') && str_contains($agent, 'CFNetwork') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') || str_contains($agent, 'X11') => 'Linux',
            default => null,
        };

        return $os === null ? $browser : "{$browser} · {$os}";
    }

    public static function since(?string $period): CarbonImmutable
    {
        return match ($period) {
            'today' => CarbonImmutable::today(),
            '30d' => CarbonImmutable::now()->subDays(30),
            default => CarbonImmutable::now()->subDays(7),
        };
    }

    /**
     * @return array{accounts: int, account_channels: array<string, int>, waitlist: int, waitlist_confirmed: int, blocked: int, blocked_reasons: array<string, int>, failed: int, throttled: int}
     */
    public function overview(CarbonImmutable $since): array
    {
        $events = SecurityEvent::query()->where('created_at', '>=', $since);
        $reasons = (clone $events)->where('type', SecurityLog::Blocked)->whereNotNull('reason')
            ->selectRaw('reason, count(*) as n')->groupBy('reason')->orderByDesc('n')->pluck('n', 'reason')->map(fn ($n): int => (int) $n)->all();

        // Accounts by where they started; Google and Apple are reasons on a website or iPhone sign-up.
        $channels = (clone $events)->where('type', SecurityLog::Signup)->where('channel', '!=', 'waitlist')
            ->selectRaw('coalesce(reason, channel) as source, count(*) as n')->groupBy('source')->orderByDesc('n')
            ->pluck('n', 'source')->map(fn ($n): int => (int) $n)->all();

        return [
            'accounts' => User::query()->where('created_at', '>=', $since)->count(),
            'account_channels' => $channels,
            'waitlist' => WaitlistSignup::query()->where('created_at', '>=', $since)->count(),
            'waitlist_confirmed' => WaitlistSignup::query()->where('confirmed_at', '>=', $since)->count(),
            'blocked' => array_sum($reasons),
            'blocked_reasons' => $reasons,
            'failed' => (clone $events)->whereIn('type', [SecurityLog::LoginFailed, SecurityLog::TwoFactorFailed])->count(),
            'throttled' => (clone $events)->where('type', SecurityLog::Throttled)->count(),
        ];
    }

    /** @return array<string, int> the domains most used in turned-away posts */
    public function blockedDomains(CarbonImmutable $since, int $limit = 3): array
    {
        return SecurityEvent::query()->where('created_at', '>=', $since)->where('type', SecurityLog::Blocked)->whereNotNull('email_domain')
            ->selectRaw('email_domain, count(*) as n')->groupBy('email_domain')->orderByDesc('n')->limit($limit)
            ->pluck('n', 'email_domain')->map(fn ($n): int => (int) $n)->all();
    }

    /** @return array{total: int, daily: int, kinds: array<string, array{sent: int, share: ?int}>} */
    public function emailsToday(): array
    {
        $kinds = [];
        foreach (array_keys((array) config('mail_budget.kinds')) as $kind) {
            $share = config("mail_budget.kinds.{$kind}.daily");
            $kinds[$kind] = ['sent' => $this->budget->sent($kind), 'share' => $share === null ? null : (int) $share];
        }

        return ['total' => $this->budget->sent(), 'daily' => (int) config('mail_budget.daily'), 'kinds' => $kinds];
    }

    /**
     * Sign-ups (accounts and waitlist) and turned-away posts per day, oldest first.
     *
     * @return list<array{day: CarbonImmutable, signups: int, blocked: int}>
     */
    public function days(int $count = 14): array
    {
        $from = CarbonImmutable::today()->subDays($count - 1);
        $perDay = fn ($query): Collection => $query->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, count(*) as n')->groupBy('day')->pluck('n', 'day')
            ->mapWithKeys(fn ($n, $day): array => [substr((string) $day, 0, 10) => (int) $n]);

        $users = $perDay(User::query());
        $waitlist = $perDay(WaitlistSignup::query());
        $blocked = $perDay(SecurityEvent::query()->where('type', SecurityLog::Blocked));

        $days = [];
        for ($i = 0; $i < $count; $i++) {
            $day = $from->addDays($i);
            $key = $day->toDateString();
            $days[] = ['day' => $day, 'signups' => ($users[$key] ?? 0) + ($waitlist[$key] ?? 0), 'blocked' => $blocked[$key] ?? 0];
        }

        return $days;
    }

    /**
     * The newest accounts and waitlist sign-ups together, with what's worth a look.
     *
     * @return list<array{name: string, email: string, where: string, via: ?string, at: CarbonImmutable, state: string, state_color: string, composer: ?int, flags: list<array{label: string, why: string, color: string}>, user_id: ?int}>
     */
    public function newestSignups(int $limit = 8): array
    {
        $disposable = app(DisposableEmail::class);
        $users = User::query()->latest()->limit($limit)->get();
        $composer = ComposerTrace::query()->whereIn('user_id', $users->pluck('id'))->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $signupEvents = SecurityEvent::query()->where('type', SecurityLog::Signup)->whereIn('user_id', $users->pluck('id'))
            ->get(['user_id', 'channel', 'reason', 'ip'])->keyBy('user_id');

        $rows = $users->map(function (User $user) use ($composer, $signupEvents, $disposable): array {
            $event = $signupEvents->get($user->id);
            $flags = [];
            if ($event?->ip !== null) {
                $sameIp = SecurityEvent::query()->where('type', SecurityLog::Signup)->where('ip', $event->ip)
                    ->where('created_at', '>=', CarbonImmutable::parse($user->created_at)->subDay())->count();
                if ($sameIp >= 3) {
                    $flags[] = ['label' => "Same IP ×{$sameIp}", 'why' => "{$sameIp} sign-ups from this IP within a day", 'color' => 'warning'];
                }
            }
            if ($disposable->isDisposable((string) $user->email)) {
                $flags[] = ['label' => 'Throwaway inbox', 'why' => 'The address is on the throwaway-inbox list', 'color' => 'danger'];
            }
            $verified = $user->email_verified_at !== null;
            $age = CarbonImmutable::parse($user->created_at);
            if (! $verified && $age->lt(now()->subDay())) {
                $flags[] = ['label' => 'Unverified '.(int) $age->diffInDays(now()).'d', 'why' => 'Hasn’t opened the verification email', 'color' => 'warning'];
            }

            return [
                'name' => (string) $user->name, 'email' => (string) $user->email,
                'where' => self::Channels[$event?->channel ?? ''] ?? 'Website',
                'via' => $event?->reason !== null ? ucfirst((string) $event->reason) : null,
                'at' => $age, 'state' => $verified ? 'Verified' : 'Not verified', 'state_color' => $verified ? 'success' : 'gray',
                'composer' => (int) ($composer[$user->id] ?? 0), 'flags' => $flags, 'user_id' => (int) $user->id,
            ];
        });

        $waitlist = WaitlistSignup::query()->latest()->limit($limit)->get()->map(function (WaitlistSignup $signup) use ($disposable): array {
            $flags = [];
            $state = 'Not confirmed';
            $color = 'gray';
            if ($signup->confirmed_at !== null) {
                // The confirmation mail goes out the moment someone signs up.
                $seconds = (int) CarbonImmutable::parse($signup->created_at)->diffInSeconds(CarbonImmutable::parse($signup->confirmed_at), true);
                $state = 'Confirmed';
                $color = 'success';
                if ($seconds < self::ScannerSeconds) {
                    $flags[] = ['label' => 'Scanner?', 'why' => "Confirmed {$seconds} s after signing up: likely a mail scanner, not a person", 'color' => 'danger'];
                }
            }
            if ($disposable->isDisposable((string) $signup->email)) {
                $flags[] = ['label' => 'Throwaway inbox', 'why' => 'The address is on the throwaway-inbox list', 'color' => 'danger'];
            }

            return [
                'name' => 'Waitlist', 'email' => (string) $signup->email, 'where' => 'Waitlist', 'via' => $signup->city,
                'at' => CarbonImmutable::parse($signup->created_at), 'state' => $state, 'state_color' => $color,
                'composer' => null, 'flags' => $flags, 'user_id' => null,
            ];
        });

        return $rows->concat($waitlist)->sortByDesc(fn (array $row) => $row['at']->getTimestamp())->take($limit)->values()->all();
    }

    /**
     * The watch's and the email budget's latest alerts.
     *
     * @return list<array{summary: string, at: CarbonImmutable, emailed: bool}>
     */
    public function alerts(int $limit = 5): array
    {
        return BureaucracyEscalation::query()
            ->where(fn ($q) => $q->where('kind', 'like', 'security\_%')->orWhere('kind', 'like', 'mail\_%'))
            ->orderByDesc('last_seen_at')->limit($limit)->get()
            ->map(fn (BureaucracyEscalation $e): array => ['summary' => (string) $e->summary, 'at' => self::escalationTime($e->last_seen_at),
                'emailed' => str_starts_with((string) $e->kind, 'security_')])->all();
    }

    /**
     * The watch's alert raised today, if any, for the banner.
     *
     * @return array{summary: string, at: CarbonImmutable}|null
     */
    public function alertToday(): ?array
    {
        $alert = BureaucracyEscalation::query()->where('kind', 'like', 'security\_%')->whereNull('resolved_at')
            ->where('last_seen_at', '>=', CarbonImmutable::today()->utc()->toDateTimeString())->orderByDesc('last_seen_at')->first();

        return $alert === null ? null : ['summary' => (string) $alert->summary, 'at' => self::escalationTime($alert->last_seen_at)];
    }

    /** Escalations store UTC clock times (Escalations::raise), which the model reads as local ones. */
    private static function escalationTime(CarbonInterface $stored): CarbonImmutable
    {
        return CarbonImmutable::parse($stored->format('Y-m-d H:i:s'), 'UTC')->setTimezone((string) config('app.timezone'));
    }

    /**
     * Everything one address did in the log's 90 days, and what it looks like.
     *
     * @return array{ip: string, counts: array<string, int>, first: ?CarbonImmutable, last: ?CarbonImmutable, browsers: list<string>, accounts: list<array{id: int, name: string, email: string, what: string}>, verdict: string, scripted: bool}
     */
    public function ipProfile(string $ip): array
    {
        $events = SecurityEvent::query()->where('ip', $ip);
        $counts = (clone $events)->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type')->map(fn ($n): int => (int) $n)->all();
        $span = (clone $events)->selectRaw('min(created_at) as first, max(created_at) as last')->first();
        $browsers = (clone $events)->whereNotNull('user_agent')->select('user_agent', DB::raw('count(*) as n'))->groupBy('user_agent')
            ->orderByDesc('n')->limit(3)->pluck('user_agent')->all();
        $accounts = (clone $events)->whereNotNull('user_id')->with('user:id,name,email')->latest('created_at')->get()
            ->unique('user_id')->take(5)->filter(fn (SecurityEvent $e) => $e->user !== null)
            ->map(fn (SecurityEvent $e): array => ['id' => (int) $e->user->id, 'name' => (string) $e->user->name, 'email' => (string) $e->user->email,
                'what' => str_replace('_', ' ', $e->type)])->values()->all();

        $first = $span?->first ? CarbonImmutable::parse($span->first) : null;
        $last = $span?->last ? CarbonImmutable::parse($span->last) : null;
        $blocked = $counts[SecurityLog::Blocked] ?? 0;
        $through = ($counts[SecurityLog::Signup] ?? 0) + ($counts[SecurityLog::Login] ?? 0);
        $headless = collect($browsers)->contains(fn (string $ua): bool => stripos($ua, 'headless') !== false);
        $hours = $first && $last ? max(1, (int) ceil($first->diffInMinutes($last) / 60)) : 1;

        [$verdict, $scripted] = match (true) {
            $blocked >= 10 && $through === 0 => ["Scripted: {$blocked} posts in {$hours} h, all turned away. Nothing it sent got through.", true],
            $blocked >= 10 => ["Mostly scripted: {$blocked} posts turned away, but {$through} sign-up or sign-in got through. Check the accounts below.", true],
            $headless => ['A headless browser: no person at a screen. Often a mail scanner opening links.', true],
            ($counts[SecurityLog::LoginFailed] ?? 0) + ($counts[SecurityLog::Throttled] ?? 0) >= 10 => ['Many wrong passwords: someone guessing, or a person who forgot theirs.', true],
            default => ['Nothing unusual from this address.', false],
        };

        return ['ip' => $ip, 'counts' => $counts, 'first' => $first, 'last' => $last, 'browsers' => $browsers,
            'accounts' => $accounts, 'verdict' => $verdict, 'scripted' => $scripted];
    }
}
