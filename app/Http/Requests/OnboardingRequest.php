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
            'arrival_planned' => ['required', 'boolean'],
            'arrival_date' => ['nullable', 'exclude_if:arrival_planned,true', 'required_unless:arrival_planned,true', 'date', 'before_or_equal:today'],
            'veedel' => ['required', 'string', Rule::in($veedels)],
            'german_level' => ['nullable', 'string', Rule::in(array_column(GermanLevel::cases(), 'value'))],
            // Whether they already hold a Deutschlandticket — drives the
            // journey-aware fare advice ("covered" vs a single ticket).
            'has_deutschlandticket' => ['nullable', 'boolean'],
            // Explicit interests — a cold-start personalisation signal that
            // shapes the home feed and composer (see Interest enum).
            'interests' => ['nullable', 'array', 'max:'.Interest::MAX_SELECT],
            'interests.*' => ['string', Rule::in(array_column(Interest::cases(), 'value'))],
            // Offered when the EU follow-up was answered "No", but never
            // required: PendingAnswers asks for entry_mode on the Bureaucracy
            // page for every situation, so a skip here is recoverable rather
            // than lost. It stays the highest-value optional answer — 17
            // applies_if references, more than any other fact.
            'entry_mode' => [Rule::excludeIf(fn (): bool => ! $this->residenceFactsApply()), 'nullable', 'string', Rule::in(['d_visa', 'visa_free', 'has_permit'])],
            // D-visa holders can give their expiry — it becomes the real
            // permit deadline instead of a vague warning.
            'visa_expires_at' => [Rule::excludeIf(fn (): bool => ! $this->residenceFactsApply() || $this->input('entry_mode') !== 'd_visa'), 'nullable', 'date_format:Y-m-d'],
            'current_residence_title' => [Rule::excludeIf(fn (): bool => ! $this->residenceFactsApply()), 'nullable', 'string', Rule::in(['national_d_visa', 'standard_work_permit', 'blue_card', 'family_reunification', 'settlement_permit_9', 'settlement_permit_18c', 'other'])],
            'residence_title_expires_at' => [Rule::excludeIf(fn (): bool => ! $this->residenceFactsApply() || ! $this->filled('current_residence_title')), 'nullable', 'date_format:Y-m-d'],
            'case_goal' => [Rule::excludeIf(fn (): bool => ! $this->residenceFactsApply()), 'nullable', 'string', Rule::in($this->availableCaseGoals())],
            'sponsor_current_title' => [Rule::excludeIf(fn (): bool => ! $this->residenceFactsApply() || $this->input('situation') !== Situation::FamilyReunification->value), 'nullable', 'string', Rule::in(['national_d_visa', 'standard_work_permit', 'blue_card_pending', 'blue_card', 'settlement_permit_9', 'settlement_permit_18c', 'other'])],
            'documented_german_level' => ['nullable', 'string', Rule::in(array_column(GermanLevel::cases(), 'value'))],
            // You cannot have moved into a Cologne address before arriving in
            // the country. The wizard used to ask the address questions BEFORE
            // the arrival question, so switching to "Still planning" left a
            // move-in date behind and this happily stored it.
            'moved_in_at' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::requiredIf(fn (): bool => $this->input('address_registration_status') === 'registrable'
                    && ! $this->boolean('arrival_planned')),
                Rule::prohibitedIf(fn (): bool => $this->input('address_registration_status') !== 'registrable'
                    || $this->boolean('arrival_planned')),
            ],
            // Optional, with a caveat we surface rather than hide: skipping it
            // leaves the Anmeldung task without its 14-day countdown, so
            // BureaucracyController::deadlineState() says so on the card and
            // links the answer. Forcing it here would block signup on a
            // question someone who has not moved in yet cannot answer.
            'address_registration_status' => ['nullable', 'string', Rule::in(['registrable', 'not_registrable', 'unsure'])],
        ];
    }

    private function requiresEntryMode(): bool
    {
        $situation = $this->input('situation');

        if (in_array($situation, [Situation::NonEuEmployee->value, Situation::FamilyReunification->value], true)) {
            return true;
        }

        return in_array($situation, [
            Situation::Student->value,
            Situation::Freelancer->value,
            Situation::DigitalNomad->value,
            Situation::Other->value,
        ], true) && $this->boolean('is_eu') === false;
    }

    private function residenceFactsApply(): bool
    {
        return match ($this->input('situation')) {
            Situation::EuEmployee->value => false,
            Situation::NonEuEmployee->value, Situation::FamilyReunification->value => true,
            default => $this->boolean('is_eu') === false,
        };
    }

    /**
     * @return list<string>
     */
    private function availableCaseGoals(): array
    {
        $currentTitle = $this->input('current_residence_title');

        if ($this->input('situation') === Situation::FamilyReunification->value) {
            return $currentTitle === 'family_reunification'
                ? ['renew_current_title', 'settlement_permit', 'understand_options']
                : ['family_reunification_permit', 'renew_current_title', 'understand_options'];
        }

        return $currentTitle === 'blue_card'
            ? ['renew_current_title', 'settlement_permit', 'understand_options']
            : ['blue_card', 'renew_current_title', 'settlement_permit', 'understand_options'];
    }
}
