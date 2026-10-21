<?php

namespace App\Mail;

use App\Auth\EmailConfirmation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The 6-digit code that confirms it's you on an account without a password
 * (EmailConfirmation). Sent at once, never queued, so the code doesn't sit
 * in a job payload.
 */
class ConfirmationCode extends Mailable
{
    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Not the code itself: subjects show on lock screens and in mail logs.
            subject: 'Your Expadu confirmation code',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.account.confirmation-code',
            with: ['minutes' => EmailConfirmation::TTL_MINUTES],
        );
    }
}
