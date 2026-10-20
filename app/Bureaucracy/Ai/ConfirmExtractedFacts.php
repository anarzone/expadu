<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\Facts\FactInputMethod;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\Questions\AnswerQuestion;
use App\Bureaucracy\Questions\QuestionSessionScope;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyProcessingConsent;
use App\Models\BureaucracyQuestionSession;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ConfirmExtractedFacts
{
    public function __construct(private QuestionSessionScope $sessions, private PersonAccess $access,
        private FactExtractionAccess $extractionAccess, private AnswerQuestion $answers) {}

    public function execute(User $actor, BureaucracyQuestionSession $session, string $candidateId, string $token, mixed $value,
        string $requestId, string $operation = 'assert', ?string $effectiveFrom = null): array
    {
        if (! Str::isUuid($candidateId) || ! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'Use valid identifiers for this confirmation.']);
        }
        $result = $this->sessions->run($actor, $session, function ($session, $person, $case) use ($actor, $candidateId, $token, $value, $requestId, $operation, $effectiveFrom): array|ValidationException {
            $this->access->authorize($actor, $person, AccessScope::RequestAi);
            $this->access->authorize($actor, $person, AccessScope::ViewPlan);
            $candidate = BureaucracyExtractionCandidate::query()->whereKey($candidateId)->where('actor_id', $actor->id)
                ->where('session_id', $session->id)->where('case_id', $case->id)->lockForUpdate()->first();
            if ($candidate === null || ! is_string($candidate->confirmation_token) || ! hash_equals($candidate->confirmation_token, $token)
                || $candidate->authority_token !== $this->access->authorityToken($actor, $person)
                || $candidate->expires_at->lessThanOrEqualTo(now())) {
                throw new AuthorizationException;
            }
            $fingerprint = ProcessingConsentStore::digest([$value, $operation, $effectiveFrom]);
            if ($candidate->state === 'confirmed') {
                if ($candidate->confirmation_request_id !== strtolower($requestId) || $candidate->confirmation_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('This suggestion was already confirmed. Refresh before editing the answer.');
                }

                return ['fact_id' => $candidate->confirmed_fact_id, 'fact_revision' => $candidate->confirmed_fact_revision, 'status' => 'already_recorded'];
            }
            $permission = BureaucracyProcessingConsent::query()->whereKey($candidate->consent_id)->lockForUpdate()->first();
            if ($candidate->state !== 'pending' || $permission === null || $permission->withdrawn_at !== null
                || $permission->state !== 'completed' || $permission->expires_at->lessThanOrEqualTo(now())
                || $permission->provider_version !== ProcessingPurpose::FactExtraction->providerVersion()
                || $permission->notice_version !== config('bureaucracy_privacy.notice_version')) {
                throw new AuthorizationException;
            }
            $question = $session->questions()->findOrFail($candidate->question_id);
            $context = ExtractionContext::for($actor, $person, $session, $question);
            if ($question->dependency_token !== $candidate->dependency_token
                || $this->extractionAccess->question($actor, $case, $question->id, ['selection' => $context->selection, 'fact_key' => $question->fact_key]) === null) {
                throw new ConflictHttpException('Answers or guidance changed. Refresh before confirming this suggestion.');
            }
            try {
                $receipt = $this->answers->execute($actor, $session, $question->id, $question->offer_token, $value, 'value', $operation, $effectiveFrom,
                    $candidate->value === $value ? FactInputMethod::ConfirmedExtraction : FactInputMethod::Structured);
            } catch (ValidationException $error) {
                // AnswerQuestion commits its malformed-answer counter; preserve that counter.
                return $error;
            }
            // The canonical fact writer invalidates all pending suggestions. Keep
            // only this successful command's short-lived, non-value retry receipt.
            $candidate->refresh();
            $candidate->update(['state' => 'confirmed', 'value' => null, 'confirmation_token' => $token, 'confirmed_fact_id' => $receipt['fact_id'],
                'confirmed_fact_revision' => $receipt['fact_revision'], 'confirmation_request_id' => strtolower($requestId),
                'confirmation_fingerprint' => $fingerprint, 'confirmed_at' => now()->utc()]);

            return $receipt;
        });
        if ($result instanceof ValidationException) {
            throw $result;
        }

        return $result;
    }
}
