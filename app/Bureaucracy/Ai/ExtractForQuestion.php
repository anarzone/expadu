<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\Ai\Contracts\ExtractsCaseFact;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Facts\TemporalFactValidator;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\Questions\QuestionSessionScope;
use App\Bureaucracy\Questions\QuestionToken;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyProcessingConsent;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExtractForQuestion
{
    public function __construct(private QuestionSessionScope $sessions, private QuestionToken $tokens, private PersonAccess $access,
        private FactExtractionAccess $extractionAccess, private FactRegistry $registry, private TemporalFactValidator $validator,
        private ProcessingConsentStore $consents, private ExtractsCaseFact $extractor) {}

    public function execute(User $actor, BureaucracyQuestionSession $session, int $questionId, string $offerToken, string $message, ?array $processing = null): array
    {
        $prepared = $this->sessions->run($actor, $session, function ($session, $person, $case) use ($actor, $questionId, $offerToken, $message, $processing): array|CaseFactExtractionRequest {
            $this->access->authorize($actor, $person, AccessScope::RequestAi);
            $this->access->authorize($actor, $person, AccessScope::ViewPlan);
            $question = $this->tokens->lock($session, $questionId, $offerToken);
            $definition = $this->registry->definition($question->fact_key);
            $context = ExtractionContext::for($actor, $person, $session, $question);
            $request = new CaseFactExtractionRequest($definition->key, $definition->question, $definition->why, $message, context: $context);
            if ($this->extractionAccess->question($actor, $case, $questionId, $request->processingContext()) === null) {
                throw new AuthorizationException;
            }
            if (! ProcessingPurpose::FactExtraction->available()) {
                return $this->fixed('unavailable');
            }
            if ($processing === null) {
                return $this->fixed('consent_required');
            }
            if (trim($message) === '' || mb_strlen($message) > 4000) {
                throw ValidationException::withMessages(['message' => 'Use a short reply of up to 4,000 characters.']);
            }
            $permit = $this->consents->grant($actor, ProcessingPurpose::FactExtraction, $request->processingContext(), $processing, $case, $question);

            return new CaseFactExtractionRequest($definition->key, $definition->question, $definition->why, $message, $permit, $context);
        });
        if (is_array($prepared)) {
            return $prepared;
        }

        // The sole transport boundary rechecks current permission before and after HTTP.
        // Do not hold the person's database locks while waiting for a provider.
        $result = $this->extractor->extract($prepared);

        return $this->sessions->run($actor, $session, function ($session, $person, $case) use ($actor, $questionId, $prepared, $result): array {
            $permission = BureaucracyProcessingConsent::query()->whereKey($prepared->permit->id)->where('actor_id', $actor->id)->lockForUpdate()->firstOrFail();
            if ($permission->withdrawn_at !== null || $permission->expires_at->lessThanOrEqualTo(now())
                || $this->extractionAccess->question($actor, $case, $questionId, $prepared->processingContext()) === null) {
                throw new AuthorizationException;
            }
            if ($permission->state === 'limited') {
                return $this->fixed('limited');
            }
            if ($result->outcome !== 'candidate' || ! $result->hasValue || $permission->state !== 'completed') {
                return $this->fixed($result->outcome === 'candidate' ? 'unavailable' : $result->outcome);
            }
            try {
                $value = $this->validator->normalize($this->registry->definition($prepared->factKey), $result->value, 'value', null);
            } catch (ValidationException) {
                return $this->fixed('invalid');
            }
            $candidate = BureaucracyExtractionCandidate::query()->firstOrCreate(['consent_id' => $permission->id], [
                'id' => $permission->id, 'case_id' => $case->id, 'question_id' => $questionId, 'session_id' => $session->id,
                'actor_id' => $actor->id, 'state' => 'pending', 'value' => $value, 'confirmation_token' => Str::random(64),
                'dependency_token' => $prepared->context->selection['dependency_token'],
                'authority_token' => $prepared->context->selection['authority_token'], 'expires_at' => $permission->expires_at,
            ]);
            if ($candidate->state !== 'pending' || $candidate->expires_at->lessThanOrEqualTo(now())) {
                return $this->fixed('invalid');
            }

            return ['outcome' => 'candidate', 'candidate_id' => $candidate->id, 'token' => $candidate->confirmation_token,
                'person_id' => $person->id, 'question_id' => $questionId, 'fact_key' => $prepared->factKey, 'value' => $candidate->value,
                'expires_at' => $candidate->expires_at->toIso8601String(), 'manual_available' => true, 'confirmation_required' => true];
        });
    }

    private function fixed(string $outcome): array
    {
        return ['outcome' => $outcome, 'manual_available' => true,
            'clarification' => $outcome === 'unclear_subject' ? 'confirm_selected_person' : null];
    }
}
