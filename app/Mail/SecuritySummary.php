<?php

namespace App\Mail;

use App\Support\AdminPanelLoading;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The admins' morning summary of the last 24 hours (security:daily-summary). */
class SecuritySummary extends Mailable
{
    /** Why a post was turned away (SecurityLog's reasons), in words. */
    public const Reasons = [
        'trap' => 'hidden field filled in',
        'no_stamp' => 'no form stamp',
        'too_fast' => 'sent within seconds',
        'disposable' => 'throwaway inbox',
        'mail_cap' => 'daily email cap',
    ];

    /**
     * @param  array<string, mixed>  $day  SecurityWatch::since()
     * @param  array{yesterday: int, today: int, daily: int}  $mail
     * @param  list<string>  $alerts  today's and yesterday's alert summaries
     */
    public function __construct(public array $day, public array $mail, public array $alerts) {}

    public function envelope(): Envelope
    {
        $signups = array_sum($this->day['signups']);
        $blocked = array_sum($this->day['blocked']);

        return new Envelope(subject: "Expadu daily security: {$signups} sign-ups, {$blocked} blocked".($this->alerts === [] ? '' : ', '.count($this->alerts).' alert'.(count($this->alerts) === 1 ? '' : 's')));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.security.summary', with: ['admin' => url('/'.AdminPanelLoading::PATH), 'reasons' => self::Reasons]);
    }
}
