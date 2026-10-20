<?php

namespace App\Onboarding;

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\FactSourceTrust;
use App\Models\BureaucracyCase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The review and write path must agree on both values and their effective periods. */
final class DraftFactChanges
{
    public function __construct(private ConfirmedFactView $facts) {}

    public function prepare(BureaucracyCase $case, array $answers): array
    {
        $result = [];
        $view = $this->facts->forCase($case, now()->toDateString());
        foreach ($answers as $key => $answer) {
            $current = $case->facts()->where('key', $key)->where('state', 'confirmed')->get()->reject(FactSourceTrust::isSynthetic(...));
            if (($view['states'][$key] ?? null) === 'conflict') {
                throw new ConflictHttpException('Resolve the conflicting recorded answers before confirming this draft.');
            }
            $existing = $current->firstWhere('id', $view['evidence'][$key]['fact_id'] ?? null) ?? $current->last();
            $operation = $answer['operation'] ?? 'assert';
            $state = $answer['answer_state'] ?? 'value';
            $target = null;
            if ($operation === 'assert' && $existing !== null) {
                if ($existing->value !== $answer['value'] || ($existing->answer_state ?? 'value') !== $state) {
                    throw ValidationException::withMessages(['answers.'.$key.'.operation' => 'Choose whether this corrects an earlier answer or records a real change.']);
                }
                $target = $existing;
            }
            if ($operation === 'correct') {
                $target = isset($answer['corrects_fact_id']) ? $case->facts()->whereKey($answer['corrects_fact_id'])->where('key', $key)
                    ->whereIn('state', ['confirmed', 'historical'])->first() : null;
                if ($target === null || FactSourceTrust::isSynthetic($target)) {
                    throw ValidationException::withMessages(['answers.'.$key.'.corrects_fact_id' => 'Choose the recorded answer being corrected.']);
                }
            } elseif (isset($answer['corrects_fact_id'])) {
                throw ValidationException::withMessages(['answers.'.$key.'.operation' => 'A correction reference requires an explicit correction.']);
            }
            if ($target !== null && isset($answer['effective_from']) && $answer['effective_from'] !== $target->effective_from?->toDateString()) {
                throw ValidationException::withMessages(['answers.'.$key.'.effective_from' => 'Corrections keep the recorded period. Use a real change to start a new period.']);
            }
            $unchanged = $operation === 'assert' && $target !== null
                && ($view['states'][$key] ?? null) === $state
                && ($view['evidence'][$key]['fact_id'] ?? null) === $target->id;
            $result[$key] = [...$answer, 'effective_from' => $target !== null ? $target->effective_from?->toDateString() : ($answer['effective_from'] ?? null),
                '_corrects_id' => $target?->id, '_write' => ! $unchanged];
        }

        return $result;
    }
}
