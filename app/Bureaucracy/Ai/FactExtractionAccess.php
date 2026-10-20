<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Cases\CurrentCaseQuestion;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\Questions\QuestionProtocol;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;

final class FactExtractionAccess
{
    public function __construct(private PersonAccess $access, private PrepareAssessmentInput $inputs, private QuestionProtocol $protocol) {}

    /** Called inside a transaction after the actor lock, and before any consent row lock. */
    public function lockCase(int $caseId): ?BureaucracyCase
    {
        $case = BureaucracyCase::query()->find($caseId);
        if ($case?->person_id !== null) {
            BureaucracyPerson::query()->whereKey($case->person_id)->lock('for no key update')->first();
        }

        return $case === null ? null : BureaucracyCase::query()->whereKey($caseId)->where('status', 'active')->lockForUpdate()->first();
    }

    public function question(User $actor, BureaucracyCase $case, int $questionId, array $context): ?BureaucracyCaseQuestion
    {
        $question = $case->questions()->whereKey($questionId)->first();
        if ($question === null || $question->answered_at !== null) {
            return null;
        }
        if ($question->session_id === null) {
            if ($case->user_id !== $actor->id) {
                return null;
            }
            $current = app(CurrentCaseQuestion::class)->for($case, true);

            return $current?->id === $questionId ? $current : null;
        }
        $person = $case->person;
        if ($person === null) {
            return null;
        }
        foreach ([AccessScope::RequestAi, AccessScope::EditFacts, AccessScope::ViewPlan] as $scope) {
            if (! $this->access->allows($actor, $person, $scope)) {
                return null;
            }
        }
        $session = BureaucracyQuestionSession::query()->whereKey($question->session_id)->where('actor_id', $actor->id)->where('case_id', $case->id)->first();
        if ($session === null || $session->expires_at->lessThanOrEqualTo(now()) || $question->offer_expires_at === null
            || $question->offer_expires_at->lessThanOrEqualTo(now()) || $question->fact_revision !== $case->fact_version
            || $question->malformed_attempts >= config('bureaucracy_questions.malformed_attempts')) {
            return null;
        }
        $selection = $context['selection'] ?? [];
        foreach (['person_id' => $person->id, 'actor_id' => $actor->id, 'session_id' => $session->id,
            'question_id' => $question->id, 'dependency_token' => $question->dependency_token,
            'authority_token' => $this->access->authorityToken($actor, $person)] as $key => $expected) {
            if (($selection[$key] ?? null) !== $expected) {
                return null;
            }
        }
        $candidate = collect($this->protocol->candidates($this->inputs->for($actor, $person, $session->jurisdiction)))->firstWhere('fact_key', $question->fact_key);

        return $candidate !== null && $candidate['kind'] === 'answer' && $candidate['dependency_token'] === $question->dependency_token
            && ($context['fact_key'] ?? null) === $question->fact_key ? $question : null;
    }
}
