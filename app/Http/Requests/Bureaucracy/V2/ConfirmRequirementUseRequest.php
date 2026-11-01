<?php

namespace App\Http\Requests\Bureaucracy\V2;

class ConfirmRequirementUseRequest extends WithdrawRequirementUseRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'evidence_id' => ['required', 'uuid'], 'evidence_version' => ['required', 'integer', 'min:1'],
            'requirement_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'confirmed' => ['required', 'accepted']];
    }
}
