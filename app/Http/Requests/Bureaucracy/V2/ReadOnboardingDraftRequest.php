<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Foundation\Http\FormRequest;

class ReadOnboardingDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('person')) === true;
    }

    public function rules(): array
    {
        return [];
    }
}
