<?php

namespace App\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the security log: who signed up, signed in or failed to, asked for
 * a password reset, and what the bot traps and the email budget turned away.
 * Each row keeps when, which account (if any), the visitor's IP and browser,
 * and only the domain of an email address. Kept 90 days (SecurityEvent).
 *
 * A failed write is reported and swallowed: the log must never stop someone
 * signing in.
 */
final class SecurityLog
{
    public const Signup = 'signup';

    public const Login = 'login';

    public const LoginFailed = 'login_failed';

    /** Too many tries on a sign-in, sign-up or reset form: the rate limit answered 429. */
    public const Throttled = 'throttled';

    public const TwoFactorFailed = 'two_factor_failed';

    public const ResetRequested = 'reset_requested';

    public const PasswordReset = 'password_reset';

    public const EmailVerified = 'email_verified';

    /** A bot trap, a throwaway domain or a mail cap turned a post away. */
    public const Blocked = 'blocked';

    /** The email budget held a mail back. */
    public const MailHeld = 'mail_held';

    /** An admin suspended, lifted, kept or deleted an account, or removed a waitlist entry (reason says which). */
    public const AdminAction = 'admin_action';

    /** Set on a request whose post was turned away, so what follows isn't logged as a real request too. */
    public const BlockedFlag = 'security.blocked';

    /**
     * @param  ?string  $channel  web, app (the iPhone API), admin, waitlist or mail; null reads it from the request
     */
    public function record(string $type, ?User $user = null, ?string $email = null, ?string $reason = null, ?string $channel = null, bool $withVisitor = true): void
    {
        try {
            $request = request();
            // An admin's action is about the account, not about where the admin sat.
            $withVisitor = $withVisitor && $type !== self::AdminAction;
            $row = [
                'type' => $type,
                'channel' => $channel ?? ($request->is('api/*') ? 'app' : 'web'),
                'user_id' => $user?->getKey(),
                'email_domain' => self::domainOf($email ?? $user?->email),
                'reason' => $reason === null ? null : Str::limit($reason, 32, ''),
                'ip' => $withVisitor ? $request->ip() : null,
                'user_agent' => $withVisitor ? self::agent($request->userAgent()) : null,
                'created_at' => now(),
            ];

            SecurityEvent::query()->insert($row);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The rate limits on sign-in, sign-up, reset and waitlist forms (web and iPhone). */
    private const WatchedLimits = ['throttle:login', 'throttle:fortify-forms', 'throttle:two-factor', 'throttle:app-two-factor', 'throttle:waitlist'];

    /**
     * Logs a 429 from one of those forms. Fortify signals a login lockout this
     * way too: with its `login` limiter on the route it fires no Lockout event.
     */
    public function throttled(Request $request): void
    {
        $route = $request->route();
        if (! $route instanceof Route || array_intersect(self::WatchedLimits, $route->gatherMiddleware()) === []) {
            return;
        }

        $email = $request->input('email');
        $this->record(self::Throttled, email: is_string($email) ? $email : null, reason: $route->getName() ?? $request->path(),
            channel: $route->named('waitlist.*') ? 'waitlist' : null);
    }

    public static function domainOf(?string $email): ?string
    {
        $at = $email === null ? false : strrchr($email, '@');
        $domain = $at === false ? '' : strtolower(trim(substr($at, 1)));

        return $domain === '' ? null : Str::limit($domain, 255, '');
    }

    private static function agent(?string $agent): ?string
    {
        $agent = trim((string) $agent);

        return $agent === '' ? null : Str::limit($agent, 255, '');
    }
}
