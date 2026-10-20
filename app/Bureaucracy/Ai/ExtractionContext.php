<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;

final readonly class ExtractionContext
{
    public function __construct(public array $selection, public array $selectedPerson) {}

    public static function for(User $actor, BureaucracyPerson $person, BureaucracyQuestionSession $session, BureaucracyCaseQuestion $question): self
    {
        return new self([
            'person_id' => $person->id, 'actor_id' => $actor->id, 'session_id' => $session->id,
            'question_id' => $question->id, 'dependency_token' => $question->dependency_token,
            'authority_token' => app(PersonAccess::class)->authorityToken($actor, $person),
        ], [
            'label' => mb_substr($person->display_label ?? 'Selected person', 0, 80),
            'is_message_author' => $person->account_user_id === $actor->id,
        ]);
    }
}
