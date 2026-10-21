<?php

namespace App\Auth;

use App\Mail\ConfirmationCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * "Confirm it's you" for an account without a password (EXP-174): the
 * Google-only accounts, which can never pass Fortify's password.confirm, so
 * couldn't download or delete their own Paperwork record. A 6-digit code goes
 * to the account's email, and the right code confirms the session exactly as
 * the password does (auth.password_confirmed_at), so password.confirm's
 * timeout applies unchanged.
 *
 * Google can't stand in: it has no re-authentication request (prompt=login
 * and max_age aren't Google parameters), so a round-trip there proves only
 * that a Google session is open (owner, 21 Oct 2026). The inbox is already
 * the bar for these accounts: a password reset goes there too.
 *
 * The code is kept server-side as an HMAC, bound to the account and to the
 * session that asked for it (a one-time nonce held in that session), for one
 * use within ten minutes, and dies after five wrong tries. An account with a
 * password always uses its password.
 */
final class EmailConfirmation
{
    public const TTL_MINUTES = 10;

    public const MAX_TRIES = 5;

    /** One answer for a wrong, expired or spent code, so none is told apart. */
    public const NOT_ACCEPTED = 'That code didn’t work. Check it, or send a new one.';

    /** The session's half of the binding: a nonce, cleared when the code is spent. */
    private const SESSION_KEY = 'email_confirmation';

    public static function applies(User $user): bool
    {
        return $user->password === null;
    }

    /**
     * Emails a new code, replacing any earlier one. False when the mail
     * couldn't be sent; nothing is kept then.
     */
    public function send(Request $request, User $user): bool
    {
        if (! self::applies($user)) {
            throw new RuntimeException('An account with a password confirms with its password.');
        }
        if (! $this->canDeliver()) {
            report(new RuntimeException('Confirmation codes can\'t be sent: the mailer delivers nothing.'));

            return false;
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $nonce = Str::random(40);
        $ttl = now()->addMinutes(self::TTL_MINUTES);

        $request->session()->put(self::SESSION_KEY, $nonce);
        Cache::put($this->key($user), [
            'session' => hash('sha256', $nonce),
            'code' => $this->hash($user, $code),
            'expires' => $ttl->getTimestamp(),
        ], $ttl);
        Cache::put($this->triesKey($user), 0, $ttl);

        try {
            // Sent now, not queued: the code never waits in a job payload.
            Mail::to($user->email)->send(new ConfirmationCode($code));
        } catch (Throwable $e) {
            $this->forget($request, $user);
            // Only the failure's kind: the mail, and so the code, stay out of logs.
            report(new RuntimeException('The confirmation code mail could not be sent ('.$e::class.').'));

            return false;
        }

        return true;
    }

    /**
     * True when the code is this session's live one: the session is then
     * confirmed and the code spent. Wrong, expired, another session's and
     * spent codes all answer false alike.
     */
    public function confirm(Request $request, User $user, string $code): bool
    {
        if (! self::applies($user)) {
            return false;
        }

        $nonce = $request->session()->get(self::SESSION_KEY);
        $entry = Cache::get($this->key($user));
        if (! is_string($nonce) || ! is_array($entry)
            || ($entry['expires'] ?? 0) < now()->getTimestamp()
            || ! hash_equals((string) ($entry['session'] ?? ''), hash('sha256', $nonce))) {
            return false;
        }

        // Counted before the comparison, atomically, so parallel guesses
        // can't share one try. A count that went missing (evicted alone)
        // comes back spent, never fresh, and keeps the code's expiry.
        Cache::add($this->triesKey($user), self::MAX_TRIES, max(1, $entry['expires'] - now()->getTimestamp()));
        $tries = Cache::increment($this->triesKey($user));
        if ($tries === false || $tries > self::MAX_TRIES) {
            $this->forget($request, $user);

            return false;
        }

        if (! hash_equals((string) ($entry['code'] ?? ''), $this->hash($user, $code))) {
            return false;
        }

        $this->forget($request, $user);
        $request->session()->passwordConfirmed();

        return true;
    }

    /**
     * Outside local and testing, a code goes out only through a mailer that
     * delivers: a log or array mailer would write it in clear and reach
     * nobody.
     */
    private function canDeliver(): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }

        $transport = config('mail.mailers.'.config('mail.default').'.transport');

        return ! in_array($transport, ['log', 'array'], true);
    }

    private function forget(Request $request, User $user): void
    {
        $request->session()->forget(self::SESSION_KEY);
        Cache::forget($this->key($user));
        Cache::forget($this->triesKey($user));
    }

    private function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->getKey().'|'.$code, (string) config('app.key'));
    }

    private function key(User $user): string
    {
        return 'email-confirmation:'.$user->getKey();
    }

    private function triesKey(User $user): string
    {
        return 'email-confirmation:'.$user->getKey().':tries';
    }
}
