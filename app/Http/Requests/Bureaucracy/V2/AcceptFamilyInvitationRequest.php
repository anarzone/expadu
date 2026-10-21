<?php

namespace App\Http\Requests\Bureaucracy\V2;

use App\Bureaucracy\People\AccessScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcceptFamilyInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasVerifiedEmail() === true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
            'scopes' => ['required', 'array', 'min:1', 'max:6'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(AccessScope::values())],
            'notice_version' => ['required', 'string', Rule::in([config('bureaucracy_family.sharing_notice_version')])],
            'accept' => ['required', static function ($attribute, $value, $fail) {
                if ($value !== true) {
                    $fail('Please explicitly accept these sharing permissions.');
                }
            }],
        ];
    }
}
