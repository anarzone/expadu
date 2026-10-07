<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Privacy\ProcessingAcceptanceRules;
use Illuminate\Validation\Validator;

class ExtractQuestionAnswerRequest extends SessionCommandRequest
{
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:64'], 'message' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
            ...ProcessingAcceptanceRules::rules()];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['token', 'message', 'processing']) !== []) {
                $validator->errors()->add('request', 'Only the offered question, reply and processing permission are accepted.');
            }
        }];
    }
}
