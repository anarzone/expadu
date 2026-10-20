<?php

namespace App\Bureaucracy\Questions;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class QuestionSessionScope
{
    public function __construct(private PersonCommandScope $scope) {}

    public function run(User $actor, BureaucracyQuestionSession $session, callable $command): mixed
    {
        $current = BureaucracyQuestionSession::query()->findOrFail($session->id);
        if ($current->actor_id !== $actor->id) {
            throw new AuthorizationException;
        }
        $person = BureaucracyCase::query()->findOrFail($current->case_id)->person;

        return $this->scope->run($actor, $person, AccessScope::EditFacts, function ($person, $case) use ($actor, $current, $command): mixed {
            $locked = BureaucracyQuestionSession::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            if ($locked->actor_id !== $actor->id || $locked->case_id !== $case->id) {
                throw new AuthorizationException;
            }
            if ($locked->expires_at->lessThanOrEqualTo(now())) {
                throw new GoneHttpException('This question session expired. Start another session; your confirmed answers are retained.');
            }

            return $command($locked, $person, $case);
        });
    }
}
