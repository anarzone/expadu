<?php

namespace App\Http\Requests\Bureaucracy\V2;

use Illuminate\Foundation\Http\FormRequest;

class ErasePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasVerifiedEmail() === true;
    }

    public function rules(): array
    {
        return [
            'confirm' => ['required', static function ($attribute, $value, $fail) {
                if ($value !== true) {
                    $fail('Explicit confirmation is required to erase this record.');
                }
            }],
        ];
    }
}
