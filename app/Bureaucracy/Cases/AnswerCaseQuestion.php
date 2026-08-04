<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\FactRegistry;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyFactConflict;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class AnswerCaseQuestion
{
    public function __construct(
        private CaseFactStore $factStore,
        private FactRegistry $factRegistry,
        private CaseMatcher $caseMatcher,
        private QuestionSelector $questionSelector,
    ) {}

    public function answer(
        User $user,
        BureaucracyCaseQuestion $question,
        mixed $value,
    ): ?BureaucracyFactConflict {
        return DB::transaction(function () use ($user, $question, $value): ?BureaucracyFactConflict {
            $caseId = BureaucracyCaseQuestion::query()
                ->whereKey($question->getKey())
                ->valueOrFail('case_id');

            $case = BureaucracyCase::query()
                ->whereKey($caseId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $case->user_id !== (int) $user->getKey()) {
                throw new AuthorizationException;
            }

            $lockedQuestion = BureaucracyCaseQuestion::query()
                ->whereKey($question->getKey())
                ->where('case_id', $case->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $normalizedValue = $this->factRegistry
                ->definition($lockedQuestion->fact_key)
                ->normalize($value);

            if ($lockedQuestion->answered_at !== null) {
                $answeredFact = BureaucracyCaseFact::query()
                    ->where('case_id', $case->getKey())
                    ->where('key', $lockedQuestion->fact_key)
                    ->where('source_reference', "question:{$lockedQuestion->getKey()}")
                    ->latest('id')
                    ->first();

                if ($answeredFact?->value === $normalizedValue) {
                    return null;
                }

                throw new AuthorizationException;
            }

            $currentQuestion = $this->questionSelector->current(
                $case,
                $this->caseMatcher->match($case),
                true,
            );

            if (! $currentQuestion instanceof BureaucracyCaseQuestion
                || (int) $currentQuestion->getKey() !== (int) $lockedQuestion->getKey()) {
                throw new AuthorizationException;
            }

            $candidate = $this->factStore->recordCandidate(
                $case,
                $lockedQuestion->fact_key,
                $normalizedValue,
                'structured_interview',
                "question:{$lockedQuestion->getKey()}",
            );
            $conflict = $this->factStore->confirmCandidate($candidate);

            $lockedQuestion->update([
                'answered_at' => now(),
                'outcome' => $conflict instanceof BureaucracyFactConflict ? 'conflict' : 'answered',
            ]);

            return $conflict;
        });
    }
}
