<?php

namespace App\Http\Requests\Bureaucracy\V2;

class CompleteOnboardingDraftRequest extends ReadOnboardingDraftRequest
{
    public function rules(): array
    {
        return ['draft_id' => ['required', 'uuid'], 'draft_version' => ['required', 'integer', 'min:1'],
            'expected_fact_revision' => ['required', 'integer', 'min:1'], 'request_id' => ['required', 'uuid'],
            'confirmed' => ['required', 'accepted']];
    }
}
