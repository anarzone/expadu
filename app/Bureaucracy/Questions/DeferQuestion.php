<?php

namespace App\Bureaucracy\Questions;

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DeferQuestion
{
    public function __construct(private QuestionSessionScope $sessions, private QuestionToken $tokens, private PrepareAssessmentInput $inputs, private QuestionProtocol $protocol) {}

    public function execute(User $actor, BureaucracyQuestionSession $session, int $questionId, string $token): void
    {
        $this->sessions->run($actor, $session, function ($session, $person, $case) use ($actor, $questionId, $token): void {
            $offer = $this->tokens->lock($session, $questionId, $token);
            if ($offer->answered_at !== null) {
                return;
            }
            $candidate = array_column($this->protocol->candidates($this->inputs->for($actor, $person, $session->jurisdiction)), null, 'fact_key')[$offer->fact_key] ?? null;
            if ($candidate === null || $candidate['dependency_token'] !== $offer->dependency_token || $case->fact_version !== $offer->fact_revision) {
                throw new ConflictHttpException('The situation or guidance changed. Refresh before deferring this question.');
            }
            $offer->update(['answered_at' => now(), 'outcome' => 'deferred']);
            $session->update(['deferred' => [...($session->deferred ?? []), $offer->fact_key => $candidate['deferral_token']]]);
            $session->increment('deferred_count');
        });
    }
}
