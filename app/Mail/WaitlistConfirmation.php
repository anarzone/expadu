<?php

namespace App\Mail;

use App\Models\WaitlistSignup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * The single double-opt-in message for a city-waitlist signup. Until the
 * recipient clicks the signed confirmation link, no other mail may be sent
 * to the address (GDPR consent is only complete on confirm).
 */
class WaitlistConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WaitlistSignup $signup) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'One tap to join the Expadu waitlist',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.waitlist.confirmation',
            with: [
                'city' => $this->signup->city,
                'confirmUrl' => URL::temporarySignedRoute(
                    'waitlist.confirm',
                    now()->addDays(7),
                    ['signup' => $this->signup->id],
                ),
            ],
        );
    }
}
