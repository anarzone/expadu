<?php

namespace App\Bureaucracy\Questions;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyPerson;
use App\Models\User;

final class QuestionInteraction
{
    public function __construct(private PersonAccess $access) {}

    /** Offer a usable review action without leaking the competing values into a plan-only grant. */
    public function for(User $actor, BureaucracyPerson $person, array $candidate): array
    {
        if (in_array($candidate['kind'], ['resolve_conflict', 'review_relationship'], true)) {
            $available = $this->access->allows($actor, $person, AccessScope::ViewFacts)
                && $this->access->allows($actor, $person, AccessScope::EditFacts);
            $candidate['action'] = [...$candidate['action'], 'person_id' => $person->id, 'available' => $available,
                'unavailable_reason' => $available ? null : 'ask_the_person_or_an_authorised_helper_to_review'];
        }

        return $candidate;
    }
}
