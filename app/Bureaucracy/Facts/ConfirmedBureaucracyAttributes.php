<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCase;
use App\Models\User;
use App\Profile\ProfileEngine;
use DomainException;

/** Compatibility input only: preserve explicit answers, never infer legal identity from a path. */
final class ConfirmedBureaucracyAttributes
{
    public function __construct(private ProfileEngine $profiles) {}

    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        if ($user->exists && BureaucracyCase::query()->where('user_id', $user->id)->where('status', 'erased')->exists()) {
            return [];
        }
        $profile = $this->profiles->build($user);
        $stored = $user->profile_attributes ?? [];
        $registered = (new FactRegistry)->all()->keys()->all();

        $attributes = [
            ...$profile->attributes,
            ...array_fill_keys($registered, null),
            // Discovery's boolean is not evidence of an answered question.
            'citizenship_group' => $user->is_eu === null ? null : ($user->is_eu ? 'eu' : 'non_eu'),
            'purpose' => $user->situation === null ? null : $profile->attributes['purpose'],
            'business_type' => null,
            'permit_track' => null,
            'sponsor' => null,
            'german_level' => $user->german_level?->value,
            'arrival_date' => $user->arrival_date?->toDateString(),
        ];

        // Do not copy arbitrary profile attributes into the decision input.
        foreach (array_unique([...array_keys($attributes), ...$registered]) as $key) {
            if (array_key_exists($key, $stored)) {
                $attributes[$key] = $stored[$key];
            }
        }

        return $this->validated($attributes);
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function validated(array $attributes): array
    {
        foreach ((new FactRegistry)->all() as $key => $definition) {
            if (($attributes[$key] ?? null) === null) {
                continue;
            }
            try {
                $attributes[$key] = $definition->normalize($attributes[$key]);
            } catch (DomainException) {
                // Historical bad data remains stored for correction, not used as evidence.
                $attributes[$key] = null;
            }
        }

        return $attributes;
    }
}
