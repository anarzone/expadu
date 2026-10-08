<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Foundation\Http\FormRequest;

class ReviewDependentAuthorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    public function rules(): array
    {
        return [
            'policy_version' => ['required', 'string', 'max:120'],
            'evidence_reference' => ['required', 'string', 'max:500'],
            'expires_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
        ];
    }
}
