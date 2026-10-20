<?php

namespace App\Bureaucracy\Questions;

use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyQuestionSession;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QuestionToken
{
    public function lock(BureaucracyQuestionSession $session, int $questionId, string $token): BureaucracyCaseQuestion
    {
        $question = BureaucracyCaseQuestion::query()->whereKey($questionId)->where('session_id', $session->id)->where('case_id', $session->case_id)->lockForUpdate()->first();
        if ($question === null || ! is_string($question->offer_token) || ! hash_equals($question->offer_token, $token)) {
            throw new AuthorizationException;
        }
        if ($question->offer_expires_at === null || $question->offer_expires_at->lessThanOrEqualTo(now())) {
            throw new ConflictHttpException('This question offer expired. Refresh for a current question.');
        }

        return $question;
    }
}
