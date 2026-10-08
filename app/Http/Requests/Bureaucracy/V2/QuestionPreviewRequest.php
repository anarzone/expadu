<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuestionPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(PersonAccess::class)->allows($this->user(), $this->route('person'), AccessScope::ViewPlan);
    }

    public function rules(): array
    {
        return ['jurisdiction' => ['required', 'string', Rule::in([...array_keys(config('bureaucracy_catalogue.jurisdictions')), 'outside_coverage'])]];
    }
}
