<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\CaseFactStore;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyFactConflict;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class AnswerCaseQuestion
{
    public function __construct(private CaseFactStore $factStore) {}

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

            if ($lockedQuestion->answered_at !== null) {
                return null;
            }

            $candidate = $this->factStore->recordCandidate(
                $case,
                $lockedQuestion->fact_key,
                $value,
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
