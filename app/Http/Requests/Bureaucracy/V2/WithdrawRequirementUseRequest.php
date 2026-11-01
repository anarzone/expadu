<?php

namespace App\Http\Requests\Bureaucracy\V2;

class WithdrawRequirementUseRequest extends EvidencePersonRequest
{
    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']];
    }
}
