<?php

namespace App\Mail;

use App\Support\AdminPanelLoading;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the admins when an hourly mark is reached (SecurityWatch). Once per rule a day. */
class SecurityAlert extends Mailable
{
    public function __construct(public string $rule, public string $summary) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Expadu security: '.rtrim($this->summary, '.'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.security.alert', with: ['admin' => url('/'.AdminPanelLoading::PATH)]);
    }
}
