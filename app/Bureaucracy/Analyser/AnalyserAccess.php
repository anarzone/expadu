<?php

namespace App\Bureaucracy\Analyser;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyCase;
use App\Models\User;

/**
 * Who may send a question about a case to the processor: someone who may read the
 * plan and request AI for the case's person (the owner, or a member granted both).
 * Checked when permission is granted and again just before the request is sent.
 */
final class AnalyserAccess
{
    public function __construct(private PersonAccess $access) {}

    public function allows(User $actor, BureaucracyCase $case): bool
    {
        $person = $case->status === 'active' ? $case->person()->first() : null;

        return $person !== null
            && $this->access->allows($actor, $person, AccessScope::ViewPlan)
            && $this->access->allows($actor, $person, AccessScope::RequestAi);
    }
}
