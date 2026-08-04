<?php

namespace App\Http\Requests\Bureaucracy;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCaseMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'question_id' => ['required', 'integer', 'min:1'],
            'message' => ['required', 'string', 'max:2000', 'regex:/\\S/u'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['question_id', 'message']) !== []) {
                $validator->errors()->add('request', 'Only question_id and message are accepted.');
            }
        }];
    }
}
