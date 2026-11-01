<?php

namespace App\Waitlist;

use App\Mail\WaitlistConfirmation;
use App\Models\User;
use App\Models\WaitlistSignup;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * "Tell me when it's covered" on Paperwork outside Cologne (EXP-80): the
 * signed-in person joins the city waitlist the website keeps, with the
 * account's own email, the city their answers give and source "paperwork".
 * Nothing new is asked.
 *
 * Consent is the website's double opt-in (owner, 18 Oct 2026): the
 * confirmation mail goes out even though the account's email is verified,
 * and the list mails nothing else until it's confirmed. Idempotent per email:
 * a second tap keeps one row and, while it's unconfirmed, re-sends the
 * confirmation at most once every ten minutes; a confirmed address, including
 * one that joined on the website, gets nothing more. A signup that already
 * exists keeps its own city and source.
 */
final class CoverageWaitlist
{
    /** The least time between two confirmation mails to one address. */
    private const RESEND_AFTER_MINUTES = 10;

    /** @return 'unconfirmed'|'confirmed' */
    public function join(User $user): string
    {
        $signup = WaitlistSignup::query()->firstOrNew(['email' => mb_strtolower((string) $user->email)]);
        if ($signup->isConfirmed()) {
            return 'confirmed';
        }

        if (! $signup->exists) {
            $signup->fill([
                'city' => Str::limit(trim((string) $user->city), 120, ''),
                'source' => 'paperwork',
            ])->save();
        } elseif ($signup->updated_at?->greaterThan(now()->subMinutes(self::RESEND_AFTER_MINUTES))) {
            // Asked again too soon: the last mail is still on its way.
            return 'unconfirmed';
        } else {
            // When this mail went: the next re-send waits from here.
            $signup->touch();
        }

        Mail::to($signup->email)->send(new WaitlistConfirmation($signup));

        return 'unconfirmed';
    }

    /**
     * Where the person stands on the list, for the page: null before they
     * ask, then unconfirmed until they confirm by mail.
     *
     * @return 'unconfirmed'|'confirmed'|null
     */
    public function state(User $user): ?string
    {
        $signup = WaitlistSignup::query()->where('email', mb_strtolower((string) $user->email))->first();

        return match (true) {
            $signup === null => null,
            $signup->isConfirmed() => 'confirmed',
            default => 'unconfirmed',
        };
    }
}
