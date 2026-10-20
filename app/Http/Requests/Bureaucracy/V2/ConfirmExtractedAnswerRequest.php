<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConfirmExtractedAnswerRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:64'], 'value' => ['present'], 'request_id' => ['required', 'uuid'],
            'confirmed' => ['required', function ($attribute, $value, $fail): void {
                if ($value !== true) {
                    $fail('Explicitly confirm this answer before saving it.');
                }
            }], 'operation' => ['sometimes', Rule::in(['assert', 'correct', 'change'])], 'effective_from' => ['nullable', 'date_format:Y-m-d']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'Only the selected suggestion and its explicit confirmation are accepted.');
            }
        }];
    }
}
