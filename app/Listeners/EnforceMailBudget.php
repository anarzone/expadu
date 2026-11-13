<?php

namespace App\Listeners;

use App\Security\MailBudget;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/** Holds any email that doesn't fit today's budget (see MailBudget). */
class EnforceMailBudget
{
    public function __construct(private readonly MailBudget $budget) {}

    public function handle(MessageSending $event): bool
    {
        $kind = $this->budget->kindOf($event->data);
        $recipients = array_map(fn (Address $to): string => $to->getAddress(), $event->message->getTo());

        $held = $this->budget->admit($kind, $recipients);
        if ($held === null) {
            return true;
        }

        Log::warning('mail.held', ['kind' => $kind, 'reason' => $held]);

        return false;
    }
}
