<?php

namespace App\Concerns;

use App\Enums\GermanLevel;
use App\Enums\Situation;
use App\Models\User;
use App\Profile\Interest;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'situation' => ['sometimes', 'nullable', Rule::enum(Situation::class)],
            'german_level' => ['sometimes', 'nullable', Rule::enum(GermanLevel::class)],
            'interests' => ['sometimes', 'nullable', 'array'],
            'interests.*' => ['string', Rule::in(array_column(Interest::cases(), 'value'))],
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
