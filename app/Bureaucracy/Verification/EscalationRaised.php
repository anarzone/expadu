<?php

namespace App\Bureaucracy\Verification;

use RuntimeException;

/** Reported (never thrown) so a high-severity escalation reaches the owner through Sentry. */
final class EscalationRaised extends RuntimeException
{
    public function __construct(public readonly string $kind, public readonly string $subject, string $summary)
    {
        parent::__construct("Paperwork needs a person [{$kind}] {$subject}: {$summary}");
    }
}
