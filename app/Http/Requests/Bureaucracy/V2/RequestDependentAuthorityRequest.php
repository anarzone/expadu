<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Foundation\Http\FormRequest;

class RequestDependentAuthorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasVerifiedEmail() === true;
    }

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
        ];
    }
}
