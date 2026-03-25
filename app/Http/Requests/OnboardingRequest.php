<?php

namespace App\Http\Requests;

use App\Enums\GermanLevel;
use App\Enums\Situation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'situation' => ['required', 'string', Rule::in(array_column(Situation::cases(), 'value'))],
            'city' => ['required', 'string', 'max:100'],
            'german_level' => ['required', 'string', Rule::in(array_column(GermanLevel::cases(), 'value'))],
            'speaks' => ['required', 'array', 'min:1'],
            'speaks.*' => ['string', 'max:50'],
            'arrival_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }
}
