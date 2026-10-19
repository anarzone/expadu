<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Foundation\Http\FormRequest;

class InspectFamilyInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasVerifiedEmail() === true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }
}
