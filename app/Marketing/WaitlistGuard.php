<?php

namespace App\Marketing;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Keeps scripts from using the waitlist to mail strangers. In November 2026
 * a bot posted hundreds of other people's addresses, and every post sent a
 * real confirmation mail from us. Two traps catch scripts without bothering
 * people, and one mail per address per day caps what any leak can send:
 *
 * - a field people never see (it sits off-screen); a script fills it in;
 * - a stamp of when the form was drawn; a post seconds later, or without the
 *   stamp, didn't come from someone typing.
 *
 * A caught post gets the same reply as a real one, so the script learns
 * nothing, but nothing is saved and no mail goes out.
 */
final class WaitlistGuard
{
    /** Nobody types an address and a city faster than this. */
    private const MinSeconds = 3;

    /** A page left open longer than this is drawn again before it posts. */
    private const MaxAgeSeconds = 86_400;

    public const Trap = 'website';

    public const Stamp = 'drawn';

    /** When the form was drawn, sealed so it can't be forged or reused later. */
    public static function stamp(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Why this post looks scripted, or null when it looks like a person.
     *
     * @return 'trap'|'no_stamp'|'too_fast'|null
     */
    public function suspicion(Request $request): ?string
    {
        if (trim((string) $request->input(self::Trap, '')) !== '') {
            return 'trap';
        }

        $drawnAt = $this->drawnAt((string) $request->input(self::Stamp, ''));

        if ($drawnAt === null) {
            return 'no_stamp';
        }

        return now()->getTimestamp() - $drawnAt < self::MinSeconds ? 'too_fast' : null;
    }

    /** True when the form was drawn so long ago it should be reloaded first. */
    public function expired(Request $request): bool
    {
        $drawnAt = $this->drawnAt((string) $request->input(self::Stamp, ''));

        return $drawnAt !== null && now()->getTimestamp() - $drawnAt > self::MaxAgeSeconds;
    }

    /**
     * Whether this address may get a confirmation mail now: one per day.
     * Claims the day's mail when it may.
     */
    public function claimMail(string $email): bool
    {
        return Cache::add('waitlist:mailed:'.sha1($email), 1, now()->addDay());
    }

    private function drawnAt(string $stamp): ?int
    {
        if ($stamp === '') {
            return null;
        }

        try {
            $value = Crypt::decryptString($stamp);
        } catch (DecryptException) {
            return null;
        }

        return ctype_digit($value) ? (int) $value : null;
    }
}
