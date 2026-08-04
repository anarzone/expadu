<?php

namespace App\Http\Requests;

use App\Bureaucracy\Facts\FactDefinition;
use App\Bureaucracy\Facts\FactRegistry;
use App\Models\BureaucracyCaseQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnswerBureaucracyCaseQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $question = $this->route('question');
        $user = $this->user();

        if (! $question instanceof BureaucracyCaseQuestion || $user === null) {
            return false;
        }

        return $user->bureaucracyCase()
            ->whereKey($question->case_id)
            ->exists();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(FactRegistry $factRegistry): array
    {
        $question = $this->route('question');

        if (! $question instanceof BureaucracyCaseQuestion) {
            return ['value' => ['prohibited']];
        }

        return [
            'value' => $this->valueRules($factRegistry->definition($question->fact_key)),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function valueRules(FactDefinition $definition): array
    {
        return match ($definition->type) {
            'enum' => ['required', 'string', Rule::in($definition->options)],
            'date' => ['required', 'date_format:Y-m-d'],
            'integer' => ['required', 'integer', 'min:0'],
            'boolean' => ['required', 'boolean'],
            default => ['prohibited'],
        };
    }
}
