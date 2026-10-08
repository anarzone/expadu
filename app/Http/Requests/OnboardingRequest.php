<?php

namespace App\Http\Requests;

use App\Bureaucracy\Facts\FactRegistry;
use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Profile\Interest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $registry = app(FactRegistry::class);
        $rules = [
            // The three answers every feature depends on stay required: situation
            // picks the plan, Veedel drives places/commute/alerts, arrival anchors
            // every arrival-relative date. Everything else is skippable.
            'situation' => ['required', Rule::in(array_column(Situation::cases(), 'value'))],
            'is_eu' => ['nullable', 'boolean'],
            'arrival_planned' => ['required', 'boolean'],
            'veedel' => ['required', 'string', Rule::in(collect(config('veedels', []))->flatten()->all())],
            'german_level' => ['nullable', Rule::in(array_column(GermanLevel::cases(), 'value'))],
            'documented_german_level' => ['nullable', Rule::in($registry->definition('german_level')->options)],
            'has_deutschlandticket' => ['nullable', 'boolean'],
            'interests' => ['nullable', 'array', 'max:'.Interest::MAX_SELECT],
            'interests.*' => ['string', Rule::in(array_column(Interest::cases(), 'value'))],
            // This old field describes whether an address seems registrable, not proof that registration is complete.
            'address_registration_status' => ['nullable', Rule::in(['registrable', 'not_registrable', 'unsure'])],
        ];
        foreach (['entry_mode', 'current_residence_title', 'case_goal', 'sponsor_current_title',
            'housing_provider_confirmation', 'registration_status'] as $key) {
            $rules[$key] = ['nullable', 'string', Rule::in($registry->definition($key)->options)];
        }
        foreach (['arrival_date', 'moved_in_at', 'visa_expires_at', 'residence_title_expires_at', 'residence_card_expires_at'] as $key) {
            $rules[$key] = ['nullable', 'date_format:Y-m-d'];
            if ($registry->definition($key)->dateSemantics === 'historical') {
                $rules[$key][] = 'before_or_equal:today';
            }
        }
        $rules['arrival_date'][] = Rule::prohibitedIf(fn () => $this->boolean('arrival_planned'));
        $rules['arrival_date'][] = Rule::requiredIf(fn () => $this->has('arrival_planned') && ! $this->boolean('arrival_planned'));
        $rules['moved_in_at'][] = Rule::prohibitedIf(fn () => $this->boolean('arrival_planned'));
        $rules['residence_title_expires_at'][] = Rule::prohibitedIf(fn () => in_array($this->input('current_residence_title'),
            ['settlement_permit_9', 'settlement_permit_18c', 'settlement_permit_unknown'], true));

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('arrival_date') && ! $validator->errors()->has('moved_in_at')
                && $this->filled('arrival_date') && $this->filled('moved_in_at') && $this->input('moved_in_at') < $this->input('arrival_date')) {
                $validator->errors()->add('moved_in_at', 'The move-in date is before the arrival recorded for this stay.');
            }
            if ($this->input('situation') === Situation::EuEmployee->value && $this->has('is_eu') && $this->input('is_eu') !== null && ! $this->boolean('is_eu')) {
                $validator->errors()->add('is_eu', 'The EU employee selection and citizenship answer differ. Please check them.');
            }
            if ($this->input('situation') === Situation::NonEuEmployee->value && $this->boolean('is_eu')) {
                $validator->errors()->add('is_eu', 'The non-EU employee selection and citizenship answer differ. Please check them.');
            }
        }];
    }
}
