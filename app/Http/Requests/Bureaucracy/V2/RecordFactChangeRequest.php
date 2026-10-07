<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\Facts\FactRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordFactChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('person')) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['key' => $this->route('key')]);
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', Rule::in(app(FactRegistry::class)->all()->keys()->all())],
            'value' => ['present'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'answer_state' => ['sometimes', Rule::in(['value', 'unknown', 'declined', 'not_applicable'])],
        ];
    }
}
