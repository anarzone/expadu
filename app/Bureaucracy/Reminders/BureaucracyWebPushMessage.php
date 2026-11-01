<?php

namespace App\Bureaucracy\Reminders;

use NotificationChannels\WebPush\WebPushMessage;

final class BureaucracyWebPushMessage extends WebPushMessage
{
    /** In-process correlation only; never included in the provider payload. */
    public function __construct(private readonly array $reminderReference) {}

    public function reference(): array
    {
        return $this->reminderReference;
    }

    public function toArray(): array
    {
        return array_diff_key(parent::toArray(), ['reminderReference' => true]);
    }
}
