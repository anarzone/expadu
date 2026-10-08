<?php

namespace App\Onboarding;

use App\Bureaucracy\Catalogue\CatalogueHash;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Facts\TemporalFactValidator;
use Illuminate\Validation\ValidationException;

final class BureaucracyDraftSchema
{
    public function __construct(private FactRegistry $registry, private TemporalFactValidator $validator) {}

    public function version(): string
    {
        return CatalogueHash::of([config('bureaucracy_onboarding'), $this->registry->version()]);
    }

    public function partial(array $answers): array
    {
        if (array_diff(array_keys($answers), config('bureaucracy_onboarding.fact_keys')) !== []) {
            throw ValidationException::withMessages(['answers' => 'This draft contains a field outside the current onboarding form.']);
        }
        foreach ($answers as $key => $answer) {
            if ($answer === null) {
                continue; // Remove this draft entry only. Confirmed history is untouched.
            }
            if (! is_array($answer) || ! array_key_exists('value', $answer)
                || array_diff(array_keys($answer), ['value', 'answer_state', 'operation', 'effective_from', 'corrects_fact_id']) !== []
                || (! is_scalar($answer['value']) && $answer['value'] !== null)
                || (is_string($answer['value']) && mb_strlen($answer['value']) > 256)
                || ! in_array($answer['answer_state'] ?? 'value', ['value', 'unknown', 'declined', 'not_applicable'], true)
                || ! in_array($answer['operation'] ?? 'assert', ['assert', 'correct', 'change'], true)
                || (isset($answer['effective_from']) && (! is_string($answer['effective_from']) || strlen($answer['effective_from']) > 16))
                || (isset($answer['corrects_fact_id']) && (! is_int($answer['corrects_fact_id']) || $answer['corrects_fact_id'] < 1))) {
                throw ValidationException::withMessages(['answers.'.$key => 'Use a bounded structured answer for this draft field.']);
            }
            $this->registry->definition($key);
        }

        return $answers;
    }

    public function merge(array $old, array $patch): array
    {
        $answers = array_replace($old, $this->partial($patch));
        $answers = array_filter($answers, fn ($entry) => $entry !== null);
        if (($answers['arrival_planned']['value'] ?? null) === true) {
            unset($answers['arrival_date'], $answers['moved_in_at'], $answers['registration_status'], $answers['housing_provider_confirmation']);
        }
        if (array_key_exists('entry_mode', $patch) && ($answers['entry_mode']['value'] ?? null) !== 'd_visa') {
            unset($answers['visa_expires_at']);
        }
        if (array_key_exists('current_residence_title', $patch)) {
            $title = $answers['current_residence_title']['value'] ?? null;
            if ($title !== ($old['current_residence_title']['value'] ?? null)) {
                foreach (['residence_title_expires_at', 'residence_card_expires_at'] as $key) {
                    if (! array_key_exists($key, $patch)) {
                        unset($answers[$key]);
                    }
                }
            }
            if ($title === null || in_array($title, ['settlement_permit_9', 'settlement_permit_18c', 'settlement_permit_unknown', 'none'], true)) {
                unset($answers['residence_title_expires_at']);
            }
        }

        return $answers;
    }

    public function confirmed(array $answers): array
    {
        $this->partial($answers);
        $normalized = [];
        foreach (config('bureaucracy_onboarding.fact_keys') as $key) {
            if (! isset($answers[$key])) {
                continue;
            }
            $answer = $answers[$key];
            try {
                $value = $this->validator->normalize($this->registry->definition($key), $answer['value'],
                    $answer['answer_state'] ?? 'value', $answer['effective_from'] ?? null);
            } catch (ValidationException $error) {
                throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $field) => ['answers.'.$key.'.'.$field => $messages])->all());
            }
            $normalized[$key] = [...$answer, 'value' => $value];
        }

        return $normalized;
    }
}
