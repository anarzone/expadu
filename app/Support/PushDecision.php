<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * What the push gate (NotificationThrottle::decide) says of one push: send it now, hold it for
 * later (quiet hours, the budget's next slot: the caller keeps it to try again), or drop it (their
 * preference, in the app only, for the brief, already sent: the caller gives up on it).
 */
final readonly class PushDecision
{
    private function __construct(public bool $send, public bool $later, public string $reason, public ?CarbonInterface $retryAt = null) {}

    public static function send(): self
    {
        return new self(true, false, 'sent');
    }

    public static function hold(string $reason, ?CarbonInterface $retryAt = null): self
    {
        return new self(false, true, $reason, $retryAt);
    }

    public static function drop(string $reason): self
    {
        return new self(false, false, $reason);
    }
}
