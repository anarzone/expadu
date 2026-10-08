<?php

namespace App\Bureaucracy\Questions;

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Facts\FactInputMethod;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\Facts\TemporalFactValidator;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class AnswerQuestion
{
    public function __construct(private QuestionSessionScope $sessions, private QuestionToken $tokens, private PrepareAssessmentInput $inputs,
        private QuestionProtocol $protocol, private FactRegistry $registry, private TemporalFactValidator $validator,
        private RecordFactChange $changes, private CorrectFact $corrections) {}

    public function execute(User $actor, BureaucracyQuestionSession $session, int $questionId, string $token, mixed $value, string $answerState = 'value', string $operation = 'assert', ?string $effectiveFrom = null, FactInputMethod $method = FactInputMethod::Structured): array
    {
        $result = $this->sessions->run($actor, $session, function ($session, $person, $case) use ($actor, $questionId, $token, $value, $answerState, $operation, $effectiveFrom, $method): array|ValidationException {
            $offer = $this->tokens->lock($session, $questionId, $token);
            $fingerprint = ProcessingConsentStore::digest([$value, $answerState, $operation, $effectiveFrom, $method->value]);
            if ($offer->answered_at !== null) {
                if ($offer->answer_fingerprint !== $fingerprint || $offer->answer_fact_id === null) {
                    throw new ConflictHttpException('This question was already handled. Refresh before changing its answer.');
                }

                return ['fact_id' => $offer->answer_fact_id, 'fact_revision' => $offer->answer_fact_revision, 'status' => 'already_recorded'];
            }
            $input = $this->inputs->for($actor, $person, $session->jurisdiction);
            $candidate = array_column($this->protocol->candidates($input), null, 'fact_key')[$offer->fact_key] ?? null;
            if ($candidate === null || $candidate['kind'] !== 'answer' || $offer->dependency_token !== $candidate['dependency_token'] || $case->fact_version !== $offer->fact_revision) {
                throw new ConflictHttpException('The underlying answers or guidance changed. Refresh for a current question.');
            }
            if ($offer->malformed_attempts >= config('bureaucracy_questions.malformed_attempts')) {
                throw new ConflictHttpException('Pause this question and check the answer format before continuing.');
            }
            try {
                if (! in_array($operation, ['assert', 'correct', 'change'], true)) {
                    throw ValidationException::withMessages(['operation' => 'Choose confirmation, correction or a real change.']);
                }
                $normal = $this->validator->normalize($this->registry->definition($offer->fact_key), $value, $answerState, $effectiveFrom);
                $existing = $case->facts()->where('key', $offer->fact_key)->where('state', 'confirmed')->orderByDesc('id')->first();
                if ($existing !== null && $existing->value !== null && $existing->value !== $normal && $operation === 'assert') {
                    throw ValidationException::withMessages(['operation' => 'Is this a correction to an earlier answer, or a real change in circumstances?']);
                }
                $fact = $existing !== null && ($operation === 'correct' || ($existing->value === $normal && $operation === 'assert'))
                    ? $this->corrections->execute($actor, $person, $existing->id, $normal, $case->fact_version, $answerState, $method)
                    : $this->changes->execute($actor, $person, $offer->fact_key, $normal, $effectiveFrom, $case->fact_version, $answerState, $method);
            } catch (ValidationException $error) {
                $offer->increment('malformed_attempts');

                return $error;
            }
            $revision = $case->fresh()->fact_version;
            $offer->update(['answered_at' => now(), 'outcome' => $answerState === 'value' ? 'answered' : $answerState,
                'answer_fingerprint' => $fingerprint, 'answer_fact_id' => $fact->id, 'answer_fact_revision' => $revision]);
            $after = $answerState === 'value' ? null
                : (array_column($this->protocol->candidates($this->inputs->for($actor, $person, $session->jurisdiction)), null, 'fact_key')[$offer->fact_key] ?? null);
            $session->update(['known_fact_revision' => $revision,
                'deferred' => $answerState === 'value' ? $session->deferred : [...($session->deferred ?? []), $offer->fact_key => $after['deferral_token'] ?? $candidate['deferral_token']]]);
            $session->increment('answered_count');

            return ['fact_id' => $fact->id, 'fact_revision' => $revision, 'status' => 'recorded'];
        });
        if ($result instanceof ValidationException) {
            throw $result;
        }

        return $result;
    }
}
