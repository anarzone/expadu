<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\Ai\Contracts\ExtractsCaseFact;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Bureaucracy\Facts\FactDefinition;
use App\Bureaucracy\Facts\FactRegistry;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseQuestion;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExtractCaseFactAction
{
    public function __construct(
        private ExtractsCaseFact $extractor,
        private BureaucracyAiQuota $quota,
        private CaseMatcher $caseMatcher,
        private QuestionSelector $questionSelector,
        private FactRegistry $factRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, int $questionId, string $message): array
    {
        $prepared = DB::transaction(function () use ($user, $questionId, $message): array {
            $case = BureaucracyCase::query()
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $case instanceof BureaucracyCase || ! $case->hasCurrentAiConsent()) {
                throw new AuthorizationException;
            }

            $question = $this->questionSelector->current(
                $case,
                $this->caseMatcher->match($case),
                true,
            );

            if (! $question instanceof BureaucracyCaseQuestion
                || (int) $question->getKey() !== $questionId
                || $question->answered_at !== null) {
                throw new AuthorizationException;
            }

            if ($this->extractor instanceof UnavailableCaseFactExtractor) {
                return ['response' => $this->fixedResponse('unavailable')];
            }

            if ($this->quota->consume($case, $message) === null) {
                return ['response' => $this->fixedResponse('limited')];
            }

            $definition = $this->factRegistry->definition($question->fact_key);

            return ['request' => new CaseFactExtractionRequest(
                factKey: $definition->key,
                question: $definition->question,
                why: $definition->why,
                message: $message,
            )];
        });

        if (isset($prepared['response']) && is_array($prepared['response'])) {
            return $prepared['response'];
        }

        $request = $prepared['request'] ?? null;

        if (! $request instanceof CaseFactExtractionRequest) {
            return $this->fixedResponse('invalid');
        }

        return $this->present($request, $this->extractor->extract($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CaseFactExtractionRequest $request, CaseFactExtractionResult $result): array
    {
        if ($result->outcome !== 'candidate' || ! $result->hasValue) {
            return $this->fixedResponse($result->outcome);
        }

        $definition = $this->factRegistry->definition($request->factKey);

        try {
            $this->factRegistry->validateConditionOperand($definition->key, $result->value);
        } catch (DomainException) {
            return $this->fixedResponse('invalid');
        }

        return [
            'outcome' => 'candidate',
            'value' => $result->value,
            'label' => $this->valueLabel($definition, $result->value),
            'message' => 'I understood this answer. Confirm it before it changes your plan.',
        ];
    }

    /**
     * @return array{outcome: string, message: string}
     */
    private function fixedResponse(string $outcome): array
    {
        return [
            'outcome' => $outcome,
            'message' => match ($outcome) {
                'unknown' => 'I could not find a clear answer. Please use the choices below.',
                'off_topic' => 'I can only help with the current bureaucracy question. Please answer it or use the choices below.',
                'unavailable' => 'The text assistant is unavailable right now. You can still use the choices below.',
                'limited' => 'You have reached today’s text-assistant limit. You can still use the choices below.',
                default => 'I could not safely interpret that answer. Please use the choices below.',
            },
        ];
    }

    private function valueLabel(FactDefinition $definition, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($definition->type !== 'enum' || ! is_string($value)) {
            return is_scalar($value) ? (string) $value : 'Unrecognized answer';
        }

        return match ($value) {
            'settlement_permit' => 'Apply for a settlement permit',
            'renew_current_title' => 'Renew my current title',
            'understand_options' => 'Understand my options',
            'family_reunification_permit' => 'Family reunification permit',
            'blue_card_pending' => 'EU Blue Card pending',
            'blue_card' => 'EU Blue Card',
            'national_d_visa' => 'National D visa',
            'standard_work_permit' => 'Standard work permit',
            'settlement_permit_18c' => 'Settlement permit (§18c)',
            'd_visa' => 'D visa',
            'visa_free' => 'Visa-free entry',
            'has_permit' => 'Already have a permit',
            'eu' => 'EU citizen',
            'non_eu' => 'Non-EU citizen',
            'a1', 'a2', 'b1', 'b2', 'c1', 'c2' => Str::upper($value),
            default => Str::headline($value),
        };
    }
}
