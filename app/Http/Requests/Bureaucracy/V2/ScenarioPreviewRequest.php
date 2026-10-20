<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScenarioPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->fresh()?->is_admin === true;
    }

    public function rules(): array
    {
        return ['jurisdiction' => ['required', 'string', Rule::in([...array_keys(config('bureaucracy_catalogue.jurisdictions')), 'outside_coverage'])]];
    }
}
