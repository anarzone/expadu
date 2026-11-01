<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordRelationshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('person')) === true;
    }

    public function rules(): array
    {
        return [
            'related_person_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(['sponsor', 'spouse', 'parent', 'child'])],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ];
    }
}
