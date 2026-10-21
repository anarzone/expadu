<?php

namespace App\Onboarding;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ReviewBureaucracyDraft
{
    public function __construct(private SaveBureaucracyDraft $drafts, private BureaucracyDraftSchema $schema, private FactRegistry $registry, private DraftFactChanges $changes, private PersonAccess $access) {}

    public function for(User $actor, BureaucracyPerson $person): array
    {
        $this->access->authorize($actor, $person, AccessScope::ViewFacts);
        $draft = $this->drafts->read($actor, $person);
        if ($draft === null || $draft['needs_review']) {
            throw new ConflictHttpException('Open the current onboarding form before reviewing this draft.');
        }
        $answers = $this->changes->prepare($person->dossier()->firstOrFail(), $this->schema->confirmed($draft['answers']));
        $summary = [];
        foreach ($answers as $key => $answer) {
            $summary[] = ['fact_key' => $key, 'question' => $this->registry->definition($key)->question,
                'value' => $answer['value'], 'answer_state' => $answer['answer_state'] ?? 'value',
                'operation' => $answer['operation'] ?? 'assert', 'effective_from' => $answer['effective_from'] ?? null];
        }

        return ['draft_id' => $draft['id'], 'draft_version' => $draft['version'], 'answers' => $summary,
            'omitted_fact_keys' => array_values(array_diff(config('bureaucracy_onboarding.fact_keys'), array_keys($answers)))];
    }
}
