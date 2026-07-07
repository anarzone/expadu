<?php

namespace App\Http\Requests;

use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Profile\Interest;
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
            // Planning mode: a not-yet-arrived expat has no arrival date. The
            // engine already renders this as the "Before you fly" phase with
            // every deadline paused, so we simply store a null arrival.
            'arrival_planned' => ['nullable', 'boolean'],
            'arrival_date' => ['nullable', 'exclude_if:arrival_planned,true', 'required_unless:arrival_planned,true', 'date', 'before_or_equal:today'],
            'veedel' => ['required', 'string', Rule::in($veedels)],
            'german_level' => ['nullable', 'string', Rule::in(array_column(GermanLevel::cases(), 'value'))],
            // Whether they already hold a Deutschlandticket — drives the
            // journey-aware fare advice ("covered" vs a single ticket).
            'has_deutschlandticket' => ['nullable', 'boolean'],
            // Explicit interests — a cold-start personalisation signal that
            // shapes the home feed and composer (see Interest enum).
            'interests' => ['required', 'array', 'min:'.Interest::MIN_SELECT, 'max:'.Interest::MAX_SELECT],
            'interests.*' => ['string', Rule::in(array_column(Interest::cases(), 'value'))],
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
