<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);

        // This is a PATCH: allow partial updates (e.g. a single settings toggle
        // like has_deutschlandticket) by only validating name/email when they
        // are present. Registration still requires them via CreateNewUser.
        $rules['name'] = ['sometimes', ...$rules['name']];
        $rules['email'] = ['sometimes', ...$rules['email']];

        return $rules;
    }
}
