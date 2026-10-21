<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Privacy\ProcessingAcceptanceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AskCaseQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(PersonAccess::class)->allows($this->user(), $this->route('person'), AccessScope::ViewPlan);
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:500', 'regex:/\p{L}/u'],
            'jurisdiction' => ['required', 'string', Rule::in(array_keys(config('bureaucracy_catalogue.jurisdictions')))],
            ...ProcessingAcceptanceRules::rules(),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['question', 'jurisdiction', 'processing']) !== []) {
                $validator->errors()->add('request', 'Only the question, jurisdiction and processing permission are accepted.');
            }
        }];
    }
}
