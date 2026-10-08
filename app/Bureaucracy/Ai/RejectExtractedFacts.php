<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\Questions\QuestionSessionScope;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyProcessingConsent;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RejectExtractedFacts
{
    public function __construct(private QuestionSessionScope $sessions) {}

    public function execute(User $actor, BureaucracyQuestionSession $session, string $candidateId, string $token): void
    {
        $this->sessions->run($actor, $session, function ($session, $person, $case) use ($actor, $candidateId, $token): void {
            $candidate = BureaucracyExtractionCandidate::query()->whereKey($candidateId)->where('session_id', $session->id)
                ->where('actor_id', $actor->id)->where('case_id', $case->id)->lockForUpdate()->first();
            if ($candidate === null || ! is_string($candidate->confirmation_token) || ! hash_equals($candidate->confirmation_token, $token)) {
                throw new AuthorizationException;
            }
            if ($candidate->state === 'confirmed') {
                throw new ConflictHttpException('This answer was already confirmed. Use the fact history to correct it.');
            }
            $candidate->update(['value' => null, 'confirmation_token' => null, 'state' => 'rejected']);
            BureaucracyProcessingConsent::query()->whereKey($candidate->consent_id)->update([
                'result' => null, 'withdrawn_at' => now()->utc(), 'state' => 'withdrawn',
            ]);
        });
    }
}
