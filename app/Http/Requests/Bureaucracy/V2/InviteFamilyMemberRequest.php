<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\People\AccessScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteFamilyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasVerifiedEmail() === true;
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer'],
            'email' => ['required', 'email', 'max:254'],
            'scopes' => ['required', 'array', 'min:1', 'max:6'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(AccessScope::values())],
        ];
    }
}
