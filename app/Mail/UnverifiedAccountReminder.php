<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/**
 * An account whose address was never verified, told a week before it's
 * deleted (accounts:prune-unverified), with a fresh verification link that
 * works until then. Sent at once, as account mail.
 */
class UnverifiedAccountReminder extends Mailable
{
    public function __construct(public User $user, public CarbonInterface $deletesOn) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your email to keep your Expadu account');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account.unverified-reminder', with: [
            'verifyUrl' => URL::temporarySignedRoute('verification.verify', $this->deletesOn, [
                'id' => $this->user->getKey(),
                'hash' => sha1((string) $this->user->getEmailForVerification()),
            ]),
            'date' => $this->deletesOn->locale('en')->isoFormat('D MMMM'),
        ]);
    }
}
