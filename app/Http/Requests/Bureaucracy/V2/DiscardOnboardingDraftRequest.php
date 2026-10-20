<?php

namespace App\Http\Requests\Bureaucracy\V2;

class DiscardOnboardingDraftRequest extends ReadOnboardingDraftRequest
{
    public function rules(): array
    {
        return ['draft_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']];
    }
}
