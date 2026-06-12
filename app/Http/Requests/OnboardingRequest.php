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
        $veedels = collect(config('veedels', []))->flatten()->all();

        return [
            'situation' => ['required', 'string', Rule::in(array_column(Situation::cases(), 'value'))],
            // Only asked when the situation doesn't imply citizenship
            // (employee situations encode it; see ProfileEngine::resolveIsEu).
            'is_eu' => ['nullable', 'boolean', Rule::requiredIf(fn () => in_array($this->input('situation'), [
                Situation::Student->value,
                Situation::Freelancer->value,
                Situation::DigitalNomad->value,
                Situation::Other->value,
            ], true))],
            'arrival_date' => ['required', 'date', 'before_or_equal:today'],
            'veedel' => ['required', 'string', Rule::in($veedels)],
            'german_level' => ['nullable', 'string', Rule::in(array_column(GermanLevel::cases(), 'value'))],
            // Asked only when the EU follow-up was answered "No" — it sets
            // the real permit deadline (visa expiry vs the 90-day window).
            'entry_mode' => ['nullable', 'string', Rule::in(['d_visa', 'visa_free', 'has_permit'])],
            // D-visa holders can give their expiry — it becomes the real
            // permit deadline instead of a vague warning.
            'visa_expires_at' => ['nullable', 'date'],
            // Temporary housing pauses the Anmeldung clock instead of
            // showing a false overdue.
            'housing_status' => ['required', 'string', Rule::in(['long_term', 'temporary'])],
        ];
    }
}
