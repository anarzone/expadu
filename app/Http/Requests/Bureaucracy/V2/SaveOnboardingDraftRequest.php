<?php

namespace App\Http\Requests\Bureaucracy\V2;

class SaveOnboardingDraftRequest extends ReadOnboardingDraftRequest
{
    public function rules(): array
    {
        return ['draft_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'step' => ['required', 'integer', 'min:1', 'max:'.config('bureaucracy_onboarding.max_steps')],
            'answers' => ['present', 'array', 'max:'.count(config('bureaucracy_onboarding.fact_keys'))]];
    }
}
