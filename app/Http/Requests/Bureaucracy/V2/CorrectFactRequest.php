<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectFactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('person')) === true;
    }

    public function rules(): array
    {
        return [
            'value' => ['present'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'answer_state' => ['sometimes', Rule::in(['value', 'unknown', 'declined', 'not_applicable'])],
        ];
    }
}
