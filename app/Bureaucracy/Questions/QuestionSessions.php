<?php

namespace App\Bureaucracy\Questions;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QuestionSessions
{
    public function __construct(private PersonCommandScope $scope, private QuestionSessionScope $sessions) {}

    public function start(User $actor, BureaucracyPerson $person, string $jurisdiction, string $requestId): BureaucracyQuestionSession
    {
        if (! Str::isUuid($requestId) || (! array_key_exists($jurisdiction, config('bureaucracy_catalogue.jurisdictions', [])) && $jurisdiction !== 'outside_coverage')) {
            throw ValidationException::withMessages(['session' => 'Select the guidance jurisdiction and use a new request identifier.']);
        }

        return $this->scope->run($actor, $person, AccessScope::EditFacts, function ($person, $case) use ($actor, $jurisdiction, $requestId): BureaucracyQuestionSession {
            $existing = BureaucracyQuestionSession::query()->where('actor_id', $actor->id)->where('request_id', $requestId)->first();
            if ($existing !== null) {
                if ($existing->case_id !== $case->id || $existing->jurisdiction !== $jurisdiction) {
                    throw new ConflictHttpException('This request identifier belongs to a different question session.');
                }

                return $existing;
            }

            return BureaucracyQuestionSession::query()->create(['case_id' => $case->id, 'actor_id' => $actor->id,
                'request_id' => $requestId, 'jurisdiction' => $jurisdiction, 'known_fact_revision' => $case->fact_version,
                'expires_at' => now()->utc()->addDays(config('bureaucracy_questions.session_days')), 'deferred' => []])->fresh();
        });
    }

    public function resume(User $actor, BureaucracyQuestionSession $session, bool $revisitDeferred): void
    {
        $this->sessions->run($actor, $session, function ($session) use ($revisitDeferred): void {
            $session->questions()->whereNull('answered_at')->where('malformed_attempts', '>=', config('bureaucracy_questions.malformed_attempts'))
                ->update(['answered_at' => now(), 'outcome' => 'exhausted']);
            $session->update(['status' => 'active', 'consecutive_offers' => 0, 'deferred' => $revisitDeferred ? [] : $session->deferred]);
        });
    }
}
