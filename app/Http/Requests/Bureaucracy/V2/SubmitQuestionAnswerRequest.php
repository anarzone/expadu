<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Validation\Rule;

class SubmitQuestionAnswerRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'value' => ['present'],
            'answer_state' => ['sometimes', Rule::in(['value', 'unknown', 'declined', 'not_applicable'])],
            'operation' => ['sometimes', Rule::in(['assert', 'correct', 'change'])],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
