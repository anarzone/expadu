<?php

namespace App\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Two traps that catch scripts posting a form, without bothering people
 * (first built for the waitlist, Nov 2026, now on every form that mails
 * whatever address is typed in):
 *
 * - a field people never see (it sits off-screen); a script fills it in;
 * - a sealed stamp of when the form was drawn; a post seconds later, or
 *   without the stamp, didn't come from someone typing.
 *
 * What a caught post gets back is up to the form; the waitlist and the
 * reset form answer as if it worked, so the script learns nothing.
 */
final class FormTraps
{
    public const Trap = 'website';

    public const Stamp = 'drawn';

    /** Nobody fills in a form faster than this. */
    private const MinSeconds = 3;

    /** A page left open longer than this is drawn again before it posts. */
    private const MaxAgeSeconds = 86_400;

    /** When the form was drawn, sealed so it can't be forged. */
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
