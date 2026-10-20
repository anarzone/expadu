<?php

namespace App\Bureaucracy\Cases;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseQuestion;

/** Both manual answers and text interpretation validate this same offered question. */
final class CurrentCaseQuestion
{
    public function __construct(
        private QuestionSelector $questions,
        private CaseMatcher $matcher,
        private PendingAnswers $pending,
    ) {}

    public function for(BureaucracyCase $case, bool $lock = false): ?BureaucracyCaseQuestion
    {
        return $this->questions->current($case, $this->matcher->match($case), $lock)
            ?? $this->questions->currentForKeys($case, $this->pending->forCase($case), $lock);
    }
}
