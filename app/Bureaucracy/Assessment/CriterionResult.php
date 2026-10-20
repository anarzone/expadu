<?php

namespace App\Bureaucracy\Assessment;

enum CriterionResult: string
{
    case Met = 'met';
    case Unmet = 'unmet';
    case Unknown = 'unknown';
    case Conflict = 'conflict';
    case Invalid = 'invalid';
    case Reconfirmation = 'needs_reconfirmation';

    public function unresolved(): bool
    {
        return ! in_array($this, [self::Met, self::Unmet], true);
    }
}
